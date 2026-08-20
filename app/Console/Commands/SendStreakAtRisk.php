<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Models\User;
use App\Services\EngagementService;
use App\Services\NotificationService;
use App\Services\ReminderWindow;
use App\Services\StreakCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The evening nudge to users about to lose a streak they have built.
 *
 * Sent **before** the streak breaks rather than after. A notification telling
 * someone they have already failed is a reason to close the app; one offering a
 * save is a reason to open it, and the difference costs nothing to get right —
 * it is entirely a matter of which day it goes out on.
 *
 * Who qualifies is StreakCalendar::isAtRisk(): a run of three or more whose last
 * qualifying day was yesterday. "Nothing logged today" needs no query of its own
 * — the observer stamps `last_day` on every qualifying write, so a `last_day` of
 * yesterday says both that yesterday counted and that today has not, yet.
 */
class SendStreakAtRisk extends Command
{
    protected $signature = 'send:streak-at-risk';
    protected $description = 'Warn users whose streak is about to break that they can still save it';

    /**
     * 20:00, on the user's clock rather than the server's.
     *
     * Late enough that "you have not logged today" is a real observation and not
     * an interruption of someone's morning, and early enough to leave four hours
     * to act on — a save offered at 23:50 is a taunt. It also sits clear of the
     * 18:30 workout reminder, so the two do not arrive together, and clear of
     * the 22:00 default quiet window, which would otherwise suppress the whole
     * feature for every user who never opened the settings screen.
     */
    private const SEND_HOUR   = 20;
    private const SEND_MINUTE = 0;

    private const CHUNK = 500;

    public function handle(): int
    {
        $notificationService = new NotificationService();
        $engagement = new EngagementService();

        // One reading of the clock for the whole run, as in the other senders:
        // calling now() per user would let a slow batch drift across the window
        // boundary and send some users' nudges on this run and again on the next.
        $now = Carbon::now();

        $due = [];
        $backedOff = [];

        User::query()
            ->whereHas('streak', function ($query) use ($now) {
                // The half of the test SQL can do. `current_days` is a plain
                // number and needs no timezone; `last_day` is compared against a
                // *local* yesterday, which differs per user, so it can only be
                // bounded here and decided in PHP below.
                //
                // The bound is safe because no timezone is more than a day from
                // UTC: a user's local yesterday always falls in
                // [utc_today - 2, utc_today]. Without it this walks the whole
                // table every half hour to find the handful of rows that matter.
                $query->where('current_days', '>=', StreakCalendar::AT_RISK_THRESHOLD)
                    ->whereBetween('last_day', [
                        $now->copy()->subDays(2)->format('Y-m-d'),
                        $now->format('Y-m-d'),
                    ]);
            })
            ->with('streak')
            ->chunkById(self::CHUNK, function ($users) use ($engagement, $now, &$due, &$backedOff) {
                foreach ($users as $user) {
                    $localNow = $user->localNow($now);

                    if (!ReminderWindow::isDue($localNow, self::SEND_HOUR, self::SEND_MINUTE)) {
                        continue;
                    }

                    $today = $localNow->format('Y-m-d');

                    if (!$user->streak->isAtRiskOn($today)) {
                        continue;
                    }

                    $copy = self::copy($user->streak->currentDaysOn($today));

                    // The once-per-day guard, and the only thing standing
                    // between a stuck scheduler and two identical nudges in one
                    // evening. It refuses a second send of a type the user has
                    // already had today, which is what spec 10 leans on 01 for.
                    // Backoff proper never fires here: an at-risk user logged
                    // yesterday, so they are one day inactive and sit in the
                    // most permissive band there is.
                    if (!$engagement->dueForReminder($user->id, NotificationLog::TYPE_STREAK_AT_RISK)) {
                        $backedOff[$user->id] = $copy;
                        continue;
                    }

                    $due[$user->id] = $copy;
                }
            });

        foreach ($backedOff as $userId => $copy) {
            $notificationService->logSkipped(
                $userId,
                $copy['title'],
                $copy['body'],
                NotificationLog::TYPE_STREAK_AT_RISK,
                'Already notified today'
            );
        }

        // Preferences, quiet hours and missing devices are decided inside
        // sendPersonalized, where every other sender already gets them.
        $result = $notificationService->sendPersonalized($due, NotificationLog::TYPE_STREAK_AT_RISK);

        $repeats = count($backedOff);

        $this->info(
            "Streak saves: {$result['sent']} sent, {$result['failed']} failed, " .
            "{$repeats} already sent today, {$result['skipped']} suppressed."
        );

        return self::SUCCESS;
    }

    /**
     * The number is the whole message.
     *
     * It is what makes this different from every other reminder in the app: the
     * user is not being told to do something, they are being told what they
     * stand to lose, and the size of that is the only argument being made. Copy
     * that hid the number behind "your streak" would ask for the same action
     * with none of the reason.
     *
     * @return array{title:string,body:string}
     */
    private static function copy(int $days): array
    {
        return [
            'title' => "🔥 {$days}-day streak",
            'body'  => "Don't break it — log anything today.",
        ];
    }
}
