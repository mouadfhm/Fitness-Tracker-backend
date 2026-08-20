<?php
namespace App\Services;

use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserDevice;
use Throwable;

class NotificationService
{
    /**
     * FCM refuses a batch request carrying more than 500 sub-messages.
     */
    private const BATCH_SIZE = 500;

    /**
     * Which screen a tapped notification of each type should open.
     *
     * The app has read `data['route']` since spec 03 shipped on the Flutter side
     * — see NavigationService.deepLinkTarget — but nothing here ever wrote it,
     * so every tap fell through to "open wherever you were". The values are the
     * four route keys that map to real screens; anything else the app resolves
     * to home rather than dropping the tap, so a typo here is survivable.
     *
     * A sender may override this per message (see sendPersonalized): copy that
     * names a weigh-in should land on the screen with the weigh-in on it, not on
     * whichever screen its notification type usually points at.
     */
    private const ROUTES = [
        NotificationLog::TYPE_MEAL_REMINDER    => 'foods',
        NotificationLog::TYPE_WORKOUT_REMINDER => 'workouts',
        NotificationLog::TYPE_SESSION_REMINDER => 'workouts',
        NotificationLog::TYPE_ACHIEVEMENT      => 'profile',
        NotificationLog::TYPE_WINBACK          => 'home',

        // Home, because the nudge says "log anything" and home is the nutrition
        // tracker — the shortest path from the notification to the action that
        // saves the streak. Sending it to `foods` would quietly narrow the offer
        // to a meal, when a workout would have done just as well.
        NotificationLog::TYPE_STREAK_AT_RISK   => 'home',
    ];

    private const DEFAULT_ROUTE = 'home';

    /**
     * @param string $type One of the NotificationLog::TYPE_* constants.
     * @param string|null $route Overrides the default screen for this type.
     */
    public function sendNotification($userId, $title, $body, string $type, ?string $route = null): ?NotificationLog
    {
        // Preferences are enforced here rather than in each command, and this is
        // the one place worth defending. Every notification in the app already
        // funnels through this method, so a new sender inherits the user's
        // choices without its author having to know they exist. Put the same
        // check in the callers instead and honouring preferences becomes a
        // convention, which is a thing that holds until the day someone is in a
        // hurry.
        $suppression = $this->suppressionReason($userId, $type);

        if ($suppression !== null) {
            return $this->logSkipped($userId, $title, $body, $type, $suppression);
        }

        // Written before the send because its id has to travel in the payload:
        // that is what the app posts back when the notification is tapped. The
        // status is corrected below if the send does not actually land.
        $log = $this->openLog($userId, $title, $body, $type);

        $device = UserDevice::where('user_id', $userId)->first();
        if (!$device) {
            $this->closeLog($log, NotificationLog::STATUS_SKIPPED, 'No registered device');
            return $log;
        }

        $data = $this->dataBlock($type, $route, $log);

        try {
            app('firebase')->send($this->buildMessage($device->device_token, $title, $body, $data));
        } catch (NotFound | InvalidArgument $e) {
            // The app was uninstalled or the token was rotated. Drop it so we
            // stop paying for a send on every reminder run.
            Log::info('Dropping stale FCM token', ['user_id' => $userId]);
            $device->delete();
            $this->closeLog($log, NotificationLog::STATUS_FAILED, $e->getMessage());
        } catch (Throwable $e) {
            // Never let a failed push break the request that triggered it
            // (unlocking an achievement, logging a meal).
            Log::error('FCM send failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->closeLog($log, NotificationLog::STATUS_FAILED, $e->getMessage());
        }

        return $log;
    }

    /**
     * Send one identical notification to many users in batched FCM calls.
     *
     * The daily commands used to loop over every user and make one HTTP call
     * each, synchronously, inside a cron-triggered process. That is fine at the
     * current size and becomes a timeout at ten times it — the failure arrives
     * as "reminders stopped going out" long before anyone thinks to look at how
     * they are sent.
     *
     * Batched through `sendAll()` rather than `sendMulticast()`, which is the
     * one deviation from the spec worth knowing about. Multicast posts a single
     * *identical* payload to a list of tokens, and our payload is not identical:
     * it carries the `log_id` the app posts back when the notification is
     * tapped. Multicast would therefore have bought batching by silently
     * switching off open-rate tracking on the only two notifications anyone
     * measures. `sendAll()` posts an array of per-token messages through the
     * same FCM batch endpoint — one HTTP call per 500 either way — and each
     * message keeps its own data.
     *
     * Kept as the front door for broadcast copy, but it is now a special case of
     * sendPersonalized() below — the same text for everybody — rather than a
     * separate path. One implementation means the preference, quiet-hours and
     * stale-token handling below cannot come to differ between the two.
     *
     * @param  int[] $userIds
     * @param  string $type One of the NotificationLog::TYPE_* constants.
     * @return array{sent:int,failed:int,skipped:int}
     */
    public function sendBulk(array $userIds, string $title, string $body, string $type): array
    {
        $copy = [];

        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            $copy[$userId] = ['title' => $title, 'body' => $body];
        }

        return $this->sendPersonalized($copy, $type);
    }

    /**
     * Send many users a notification each, with its own copy, in batched calls.
     *
     * Spec 06 says personalized copy "cannot use sendMulticast" and must fall
     * back to sending one at a time. The first half is true and the second no
     * longer follows, because sendBulk() never used multicast in the first place
     * — see the note above. `sendAll()` takes an array of complete, independent
     * messages, so copy that names the person costs exactly what broadcast copy
     * costs: one HTTP call per 500 users. Dropping to per-user sends here would
     * have given up spec 04's batching on the app's two highest-volume
     * notifications in exchange for nothing.
     *
     * @param array<int,array{title:string,body:string,route?:string}> $copyByUserId
     * @param string $type One of the NotificationLog::TYPE_* constants.
     * @return array{sent:int,failed:int,skipped:int}
     */
    public function sendPersonalized(array $copyByUserId, string $type): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        if ($copyByUserId === []) {
            return $result;
        }

        $userIds = array_map('intval', array_keys($copyByUserId));

        // Two passes. Suppressed users and users with no device are decided and
        // logged first so that the batches which do go out are full ones: a
        // single pass would let 500 candidates become 40 messages and turn the
        // batching back into what it replaced.
        $recipients = [];

        foreach (array_chunk($userIds, self::BATCH_SIZE) as $chunk) {
            $tokens = UserDevice::whereIn('user_id', $chunk)->pluck('device_token', 'user_id');

            foreach ($chunk as $userId) {
                $title = $copyByUserId[$userId]['title'];
                $body  = $copyByUserId[$userId]['body'];

                $suppression = $this->suppressionReason($userId, $type);

                if ($suppression !== null) {
                    $this->logSkipped($userId, $title, $body, $type, $suppression);
                    $result['skipped']++;
                    continue;
                }

                $token = $tokens[$userId] ?? null;

                if (!$token) {
                    $this->logSkipped($userId, $title, $body, $type, 'No registered device');
                    $result['skipped']++;
                    continue;
                }

                $recipients[$userId] = $token;
            }
        }

        foreach (array_chunk($recipients, self::BATCH_SIZE, true) as $batch) {
            $this->sendBatch($batch, $copyByUserId, $type, $result);
        }

        return $result;
    }

    /**
     * One FCM call for up to BATCH_SIZE recipients.
     *
     * @param array<int,string> $recipients user id => device token
     * @param array<int,array{title:string,body:string,route?:string}> $copyByUserId
     * @param array{sent:int,failed:int,skipped:int} $result
     */
    private function sendBatch(array $recipients, array $copyByUserId, string $type, array &$result): void
    {
        $messages = [];

        // Keyed by token because that is all the report gives back to identify a
        // sub-response by. A list per token rather than a single log: the same
        // physical device can be registered against two users (user_devices is
        // upserted on user_id), and both of those rows deserve their outcome.
        $logsByToken = [];

        foreach ($recipients as $userId => $token) {
            $title = $copyByUserId[$userId]['title'];
            $body  = $copyByUserId[$userId]['body'];

            $log = $this->openLog($userId, $title, $body, $type);

            $data = $this->dataBlock($type, $copyByUserId[$userId]['route'] ?? null, $log);

            try {
                $messages[] = $this->buildMessage($token, $title, $body, $data);
            } catch (Throwable $e) {
                // A token the SDK will not even accept as a target. Left out of
                // the batch rather than allowed to reject the whole request.
                Log::warning('Skipping unusable FCM token', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $this->closeLog($log, NotificationLog::STATUS_FAILED, $e->getMessage());
                $result['failed']++;
                continue;
            }

            if ($log) {
                $logsByToken[$token][] = $log;
            }
        }

        if ($messages === []) {
            return;
        }

        $attempted = count($messages);

        try {
            $report = app('firebase')->sendAll($messages);
        } catch (Throwable $e) {
            // The whole batch failed to leave the box — credentials, network,
            // a 500 from Google. As everywhere else here, it must not take the
            // caller down with it; the next scheduled run will try again.
            Log::error('FCM bulk send failed', [
                'type' => $type,
                'recipients' => $attempted,
                'error' => $e->getMessage(),
            ]);

            foreach ($logsByToken as $logs) {
                foreach ($logs as $log) {
                    $this->closeLog($log, NotificationLog::STATUS_FAILED, $e->getMessage());
                }
            }

            $result['failed'] += $attempted;
            return;
        }

        $failed = 0;

        foreach ($report->failures()->getItems() as $item) {
            $failed++;
            $error = $item->error() ? $item->error()->getMessage() : 'Unknown FCM error';

            foreach ($logsByToken[$item->target()->value()] ?? [] as $log) {
                $this->closeLog($log, NotificationLog::STATUS_FAILED, $error);
            }
        }

        $result['failed'] += $failed;
        $result['sent'] += max(0, $attempted - $failed);

        // The app was uninstalled or the token was rotated. One delete for the
        // batch, in place of the per-send NotFound/InvalidArgument catch that
        // does this job on the single-send path.
        $stale = array_values(array_unique(array_merge($report->invalidTokens(), $report->unknownTokens())));

        if ($stale !== []) {
            Log::info('Dropping stale FCM tokens', ['count' => count($stale)]);
            UserDevice::whereIn('device_token', $stale)->delete();
        }
    }

    /**
     * The FCM `data` block: everything the app needs that is not the visible text.
     *
     * `log_id` is what the app posts back on a tap (spec 01) and `route` is the
     * screen that tap should land on (spec 03). Both are strings because FCM's
     * data payload is string-to-string and an int would arrive as one anyway,
     * having been converted somewhere neither end controls.
     */
    private function dataBlock(string $type, ?string $route, ?NotificationLog $log): array
    {
        $data = [
            'type'  => $type,
            'route' => $route ?? self::ROUTES[$type] ?? self::DEFAULT_ROUTE,
        ];

        if ($log) {
            $data['log_id'] = (string) $log->id;
        }

        return $data;
    }

    /**
     * Shared by both send paths so the Android channel id cannot drift between
     * them. It has to match `firebase_service.dart` and the manifest's
     * `default_notification_channel_id`, and a reminder posted to a channel the
     * app never created is a notification nobody sees.
     */
    private function buildMessage(string $token, string $title, string $body, array $data): CloudMessage
    {
        return CloudMessage::withTarget('token', $token)
            ->withNotification(Notification::create($title, $body))
            ->withData($data)
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'fitness_reminders',
                    'sound' => 'default',
                ],
            ]);
    }

    /**
     * Record a notification we chose not to send.
     *
     * A withheld reminder is a decision, and a decision that leaves no trace is
     * indistinguishable from a bug. The row carries the copy the user would
     * have received, so "why did this person hear nothing for a week?" is
     * answerable from the table alone.
     *
     * Note for anyone querying this: `sent_at` is populated on skipped rows too
     * (the column is NOT NULL) and means "when the decision was made", not that
     * anything was delivered. Filter on status before reading it.
     */
    public function logSkipped($userId, $title, $body, string $type, string $reason): ?NotificationLog
    {
        try {
            return NotificationLog::create([
                'user_id' => $userId,
                'type'    => $type,
                'title'   => $title,
                'body'    => $body,
                'status'  => NotificationLog::STATUS_SKIPPED,
                'error'   => Str::limit($reason, 250),
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Could not log skipped notification', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Why this send should not happen, or null to go ahead.
     *
     * Returns a sentence rather than a boolean because it lands in the log row's
     * `error` column, where "Quiet hours 22:00-08:00 (Europe/Paris)" answers the
     * support question on its own and a bare `false` starts an investigation.
     *
     * Fails open. The realistic failure here is the table not existing yet —
     * code deployed a moment before its migration ran — and during that window
     * failing open means behaving exactly as the app did yesterday. Failing
     * closed would instead stop every notification for every user, silently,
     * at the moment nobody is watching for it. A user's opt-out surviving a few
     * minutes longer than it should is the smaller of those two.
     */
    private function suppressionReason($userId, string $type): ?string
    {
        try {
            $preferences = NotificationPreference::forUser((int) $userId);

            if (!$preferences->allowsType($type)) {
                return "Disabled by user preference: {$type}";
            }

            // Quiet hours are the user's hours. Evaluating them against the
            // server's clock would mute a user in Tokyo through their afternoon
            // and leave them audible at 2am, which is precisely backwards.
            $user = User::find($userId);
            $timezone = $user ? $user->timezoneOrDefault() : User::DEFAULT_TIMEZONE;
            $localNow = $user ? $user->localNow() : now()->setTimezone($timezone);

            if ($preferences->isQuietAt($localNow)) {
                return sprintf(
                    'Quiet hours %s-%s (%s)',
                    QuietHours::format($preferences->quiet_from),
                    QuietHours::format($preferences->quiet_to),
                    $timezone
                );
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Could not read notification preferences', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Logging is bookkeeping, not the point of the call. If the table is
     * missing or the write fails, the notification still goes out.
     */
    private function openLog($userId, $title, $body, string $type): ?NotificationLog
    {
        try {
            return NotificationLog::create([
                'user_id' => $userId,
                'type'    => $type,
                'title'   => $title,
                'body'    => $body,
                'status'  => NotificationLog::STATUS_SENT,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Could not open notification log', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function closeLog(?NotificationLog $log, string $status, ?string $error = null): void
    {
        if (!$log) return;

        try {
            $log->update([
                'status' => $status,
                // `error` is a varchar; an unbounded exception message would
                // fail the write and take the caller down with it.
                'error'  => $error === null ? null : Str::limit($error, 250),
            ]);
        } catch (Throwable $e) {
            Log::error('Could not close notification log', [
                'log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
