<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Models\ScheduledWorkout;
use App\Models\User;
use App\Models\WorkoutLog;
use App\Services\NotificationService;
use App\Services\SessionReminderWindow;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reminds users about sessions they put in their own calendar.
 *
 * Every other notification in this app is something we decided to say. This one
 * repeats back a commitment the user made — "Leg Day, in an hour" — which is a
 * different kind of message and should behave like one.
 *
 * Two consequences of that show up below and are worth stating up front.
 *
 * The first is that the engagement backoff does not apply here. SendDailyReminder
 * thins itself out for users who have drifted away, and rightly so: it has
 * nothing to say to them beyond "you did not log today". This does. Backing it
 * off would mean withholding the reminder for a session the user deliberately
 * scheduled because they have not opened the app lately, which is precisely
 * backwards — that reminder is the one thing most likely to bring them back.
 * Volume stays bounded anyway: one send per scheduled session, ever, enforced by
 * `reminded_at`, and nothing at all for a user with an empty calendar.
 *
 * The second is that it sends one at a time, through sendNotification() rather
 * than sendBulk(). Batching wants one identical payload for many users, and
 * every message here names a different workout.
 */
class SendWorkoutSessionReminder extends Command
{
    protected $signature = 'send:workout-session-reminder';
    protected $description = 'Remind users about workouts they scheduled, shortly before they start';

    /**
     * How many exercises to name in the body.
     *
     * Three fits an Android notification's collapsed two lines alongside a
     * workout name of ordinary length. A fourth is not read, it just pushes the
     * first three into an ellipsis.
     */
    private const MAX_EXERCISES = 3;

    /**
     * How far either side of now to look, in raw column terms.
     *
     * `scheduled_at` is a local wall clock and this comparison runs against the
     * server's UTC clock, so the two are out by the user's offset — up to +14
     * and -12 hours across the tz database. The bound only has to be loose
     * enough never to exclude a row the per-user check would have matched; that
     * check is what actually decides. A day back and two forward covers the
     * worst offset roughly twice over.
     */
    private const LOOKBEHIND_DAYS = 1;
    private const LOOKAHEAD_DAYS  = 2;

    public function handle(): void
    {
        $notificationService = new NotificationService();

        // One reading of the clock for the whole run, as in the daily commands:
        // a per-user now() would let a slow batch drift across a window edge.
        $now = Carbon::now();

        $sent = 0;
        $suppressed = 0;
        $alreadyTrained = 0;

        ScheduledWorkout::query()
            // gym_exercises is the "if cheaply available" part of the spec, and
            // eager loading is what makes it cheap: three queries for the whole
            // run instead of two per candidate session.
            ->with(['user', 'workout.gym_exercises'])
            ->whereNull('reminded_at')
            ->whereBetween('scheduled_at', [
                $now->copy()->subDays(self::LOOKBEHIND_DAYS)->toDateTimeString(),
                $now->copy()->addDays(self::LOOKAHEAD_DAYS)->toDateTimeString(),
            ])
            ->chunkById(200, function ($sessions) use ($notificationService, $now, &$sent, &$suppressed, &$alreadyTrained) {
                foreach ($sessions as $session) {
                    $user = $session->user;

                    // The foreign key cascades, so this should be unreachable.
                    // It is here because the alternative to a two-line guard is
                    // one orphaned row stopping every reminder behind it.
                    if (!$user) {
                        continue;
                    }

                    // Parsed in the user's zone rather than the server's,
                    // because that is the zone it was written in — see the note
                    // on ScheduledWorkout::$casts.
                    $scheduledAt = Carbon::parse($session->scheduled_at, $user->timezoneOrDefault());

                    if (!SessionReminderWindow::isDue($user->localNow($now), $scheduledAt)) {
                        continue;
                    }

                    if ($this->alreadyTrainedOn($user, $scheduledAt)) {
                        $this->markReminded($session, $now);
                        $alreadyTrained++;
                        continue;
                    }

                    [$title, $body] = $this->compose($session, $scheduledAt);

                    $log = $notificationService->sendNotification(
                        $user->id,
                        $title,
                        $body,
                        NotificationLog::TYPE_SESSION_REMINDER
                    );

                    // Stamped whatever the outcome, including a send that
                    // preferences or quiet hours suppressed. The retry this
                    // gives up is not worth having: an hour later the session
                    // has started, so what would go out is a reminder for
                    // something already under way, and the only other product
                    // of trying again is one more skipped row in
                    // notification_logs every hour for a user who asked for
                    // silence. The window is an hour wide against an hourly
                    // command in any case, so a second attempt needs the
                    // scheduler to misfire — which is the case this column
                    // exists for.
                    $this->markReminded($session, $now);

                    if ($log && $log->status === NotificationLog::STATUS_SENT) {
                        $sent++;
                    } else {
                        $suppressed++;
                    }
                }
            });

        $this->info(
            "Session reminders: {$sent} sent, {$suppressed} suppressed or failed, " .
            "{$alreadyTrained} already trained that day."
        );
    }

    /**
     * Has the user already trained on the day this session is for?
     *
     * Both tables are consulted, and that is not belt-and-braces. The spec names
     * `workout_logs`, which is the v2 table and the one a reader would expect —
     * but it is empty in production and nothing writes to it. Completed sessions
     * land in the v1 `workouts` table via /api/workouts/add, which is what
     * SendDailyReminder has always checked. Reading only the documented table
     * would mean the "logging it beforehand suppresses the reminder" case never
     * fires for a real user; reading only the used one would break on the day v2
     * logging is switched on.
     *
     * Both `workout_date` columns hold dates the client sent, so they are
     * compared against the session's local date rather than a UTC one.
     */
    private function alreadyTrainedOn(User $user, CarbonInterface $scheduledAt): bool
    {
        $date = $scheduledAt->toDateString();

        return WorkoutLog::where('user_id', $user->id)->whereDate('workout_date', $date)->exists()
            || $user->workouts()->whereDate('workout_date', $date)->exists();
    }

    /**
     * @return array{0:string,1:string} title, body
     */
    private function compose(ScheduledWorkout $session, CarbonInterface $scheduledAt): array
    {
        $name = $session->displayName();

        // Sessions with no real time are reminded on the morning of the day, so
        // the copy has to say "today" — promising one that starts in an hour
        // when the user never picked an hour is a claim the app cannot keep.
        $timed = SessionReminderWindow::hasMeaningfulTime($scheduledAt);

        $title = $timed ? "🏋️ {$name} in an hour" : "🏋️ {$name} today";

        $exercises = $this->exerciseNames($session);

        if ($exercises !== []) {
            return [$title, 'Your plan says ' . implode(', ', $exercises) . '.'];
        }

        // A workout with no exercises attached, or one whose row is gone. Still
        // worth sending — the user scheduled it — but the body has to carry
        // itself without naming anything.
        return [
            $title,
            $timed
                ? 'You put this one in your calendar. Time to get moving.'
                : "It's on today's schedule. Don't let it slide.",
        ];
    }

    /**
     * @return string[]
     */
    private function exerciseNames(ScheduledWorkout $session): array
    {
        $workout = $session->workout;

        if (!$workout) {
            return [];
        }

        // take() on the loaded collection, not a limit on a query: the relation
        // is already in memory from the eager load above, and re-querying here
        // would put the N+1 back one row at a time.
        return $workout->gym_exercises
            ->take(self::MAX_EXERCISES)
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Never let bookkeeping take the batch down.
     *
     * A failed stamp costs at most a duplicate, and only if the scheduler also
     * misfires inside the same hour; a thrown exception costs every reminder
     * after this one in the run.
     */
    private function markReminded(ScheduledWorkout $session, Carbon $now): void
    {
        try {
            $session->update(['reminded_at' => $now]);
        } catch (Throwable $e) {
            $this->warn("Could not stamp scheduled workout {$session->id}: {$e->getMessage()}");
        }
    }
}
