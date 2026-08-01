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
     * @param string $type One of the NotificationLog::TYPE_* constants.
     */
    public function sendNotification($userId, $title, $body, string $type): ?NotificationLog
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

        $data = ['type' => $type];
        if ($log) {
            $data['log_id'] = (string) $log->id;
        }

        $message = CloudMessage::withTarget('token', $device->device_token)
            ->withNotification(Notification::create($title, $body))
            ->withData($data)
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'fitness_reminders',
                    'sound' => 'default',
                ],
            ]);

        try {
            app('firebase')->send($message);
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
