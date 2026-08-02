# Spec 10 — Reminder query batching

Status: designed
Date: 2026-08-01
Depends on: 04 (multicast batching), 08 (preferences & quiet hours), 09 (per-user timezone)

## Problem

Spec 04 removed the per-user HTTP call from the two daily reminder commands: 600 due
users now cost two FCM requests instead of 600. What it did not remove is the per-user
*database* cost, which is now the dominant term.

Per candidate user, per run:

| Where | Query | Count |
|---|---|---|
| `SendDaily*Reminder::hasLoggedToday()` | `exists()` on `workouts` / `meals` | 1 |
| `EngagementService::dueForReminder()` | `User::find()` — a second fetch of a user the command already holds from `chunkById` | 1 |
| `EngagementService::daysSinceLastSent()` | `max('sent_at')` on `notification_logs` | 1 |
| `NotificationService::suppressionReason()` | `NotificationPreference::forUser()` | 1 |
| `NotificationService::suppressionReason()` | `User::find()` — a third fetch of the same user | 1 |

Roughly five reads per user per run. At 10k users the 30-minute scheduler tick issues
~50k queries where two FCM calls now suffice.

This is not a tail case. `User::timezoneOrDefault()` falls back to
`Africa/Casablanca` for anyone whose device has never reported a zone, so the bulk of
the table shares one timezone and lands in the *same* 30-minute `ReminderWindow`. The
worst case is the ordinary case.

## Constraints

Carried forward from `00-index.md`:

- **Behaviour must not change.** Per-user timezone logic in `User::localNow`, quiet
  hours, and the engagement backoff bands all stay exactly as they are.
- **A failed push must never break the caller.**
- **Do not run the phpunit suite.** The local PHP has no `pdo_sqlite`, so the suite
  would run against — and wipe — the dev database at `127.0.0.1/fitness`. Verification
  is by `php artisan tinker <script>` inside a rolled-back `DB::transaction`, faking the
  `firebase` container binding, with query counts asserted via `DB::listen`.

## Design

### 1. `EngagementService`

`dueForReminder(User $user, string $type): bool` takes the model instead of an id and
drops the `User::find`. The `if (!$user) return false` branch goes with it: both call
sites hold a model from `chunkById`, and they are the only callers in the tree.

New batch entry point, one grouped query per chunk:

```php
public function dueForReminderMany(Collection $users, string $type): array  // id => bool
```

```sql
SELECT user_id, MAX(sent_at) AS last_sent_at
FROM notification_logs
WHERE user_id IN (...) AND type = ? AND status = 'sent'
GROUP BY user_id
```

The existing composite index `(user_id, type, sent_at)` covers this as a prefix. No new
index.

Decisions still come from the existing pure `shouldSend()`, unchanged, called per user.

The day-boundary arithmetic currently inside `daysSinceLastSent()` moves to a private
`daysSince(?string $lastSentAt): ?int` that **both** the single and batch paths call.
The `startOfDay()->diffInDays()` comparison exists to stop a run that fires a few
seconds early from silently halving the reminder rate; having two copies of it is how
that protection erodes.

`Carbon::now()` stays read inside the per-user loop rather than hoisted, so batching
does not collapse it to one reading.

### 2. `LocalDayWindow` — new service, pure, no database

Both commands carry a near-identical `hasLoggedToday()`, differing only in the relation.
The shared part is the interesting part: converting a user's local day to the UTC range
`created_at` is stored in.

Candidates within a chunk all passed the same `ReminderWindow::isDue`, so their wall
clocks read the same time and only the timezone offset separates their day boundaries.
`LocalDayWindow` groups a collection of users by their identical `(startUtc, endUtc)`
pair. Each command then runs one query per distinct window:

```php
Workout::whereIn('user_id', $group->ids())
    ->whereBetween('created_at', [$group->start, $group->end])
    ->distinct()
    ->pluck('user_id');
```

That is the old query with `where` swapped for `whereIn`; the UTC conversion is
untouched. Typically one query per chunk for this app, bounded by the number of live tz
offsets in the pathological case — still far below one per user. The FK index on
`user_id` serves it.

**Rejected:** one widened `min(start)..max(end)` query per chunk (always exactly one
query, ~50h span). It fetches rows it then discards and moves the day-boundary
comparison out of SQL into PHP, for a saving that only materialises in a spread this
app does not have.

### 3. `NotificationService`

`sendBulk()` keeps its `array $userIds` signature, so `AchievementService` and the
single-send path are untouched. Per 500-chunk it adds two lookups beside the existing
`UserDevice` one:

- `User::whereIn('id', $chunk)->get()->keyBy('id')`
- `NotificationPreference::forUsers($chunk)`

`suppressionReason` splits in two:

- `suppressionReasonFor(?User $user, NotificationPreference $prefs, string $type): ?string`
  — the decision, no queries, shared by both paths so they cannot diverge.
- `suppressionReason($userId, string $type): ?string` — unchanged for
  `sendNotification()`; performs the two lookups and wraps them in the same try/catch.

#### Two failure modes, kept distinct

This is the part that is easy to get wrong, because both look like "no preferences".

- **Row absent, load succeeded** → `NotificationPreference::defaultFor($userId)`: an
  unsaved model carrying `DEFAULTS`, identical to what `firstOrNew` returns today.
  Quiet hours 22:00–08:00 still apply. `forUser()` and the new `forUsers()` both route
  through `defaultFor()`, so the "no row means DEFAULTS" invariant the model's doc
  comment defends lives in exactly one place.
- **Load threw** (the realistic case being the table not existing yet, code deployed a
  moment before its migration) → suppression is `null` for the whole chunk: no
  preference check and **no quiet hours**. That is precisely today's per-user catch,
  widened to the chunk. Falling back to `DEFAULTS` here would instead start muting every
  user through 22:00–08:00 during the deploy window — a behaviour change, silent, at the
  moment nobody is watching for it.

`$user->localNow()` stays argument-less inside the loop rather than being threaded with
the command's `$now`. Threading it would arguably be more correct; it would also shift
when quiet hours are evaluated, and this spec does not change behaviour.

### 4. The two commands

The chunk callback becomes three passes, preserving the exact filter order — window,
weekday, logged-today, engagement — and therefore preserving which users get an
engagement-backoff `logSkipped` row and which are dropped silently before reaching it:

| Pass | Work | Queries |
|---|---|---|
| 1 | `ReminderWindow::isDue` + weekday (workout only) | 0 |
| 2 | logged today, via `LocalDayWindow` | ~1 |
| 3 | `dueForReminderMany` | 1 |

`$due` still accumulates across chunks into a single `sendBulk` call, unchanged: batching
per chunk would let a chunk holding 40 due users spend a whole FCM request on 40
messages.

## Result

Per 500 users in one timezone: **3 reads in the command, 3 in `sendBulk`**, against
~2,500 today. At 10k users a tick goes from ~50k reads to ~120.

Notification log inserts are untouched. Batching those would need the auto-increment id
back before the payload is built — `log_id` is what the app posts back on tap, and spec
01 exists to make that measurable — so the trade is not worth making here.

## Verification

No phpunit (see Constraints). A tinker harness, inside a `DB::transaction` that always
rolls back, with `app('firebase')` rebound to a fake exposing `sendAll()` and a report
stub (`failures()`, `invalidTokens()`, `unknownTokens()`), and `DB::listen` counting
queries.

Fixture covers: quiet-hours user, preference-disabled user, no-device user, weekend
user, logged-today user, dormant >60 days, mid-band backoff, and users across at least
three timezones including a half-hour offset.

The harness runs against **HEAD first**, and its outcome table — which ids reach `$due`,
which get skip rows and with what reason, and the query count — is saved as a baseline.
The change is then implemented and the harness re-run and diffed. Behaviour equivalence
is demonstrated rather than asserted, and both query counts come from the same
instrument.

A second pass at 5 users versus 50 confirms the per-run count is flat rather than merely
smaller.

### Measured

Fixture of 11 users across four zones, of whom 9 are in-window; the dev database's 16
existing users sit outside both windows and are filtered at zero query cost.

| Scenario | Before | After |
|---|---|---|
| meal reminder | 46 | 15 |
| workout reminder | 46 | 15 |
| workout, Saturday | 1 | 1 |
| meal, +5 in-window users | 70 | 20 |
| meal, +50 in-window users | 340 | 65 |

Slope from the last two rows: **6.0 queries per user before, 1.0 after**, and that
remaining 1.0 is the `notification_logs` insert — a write, and the one thing this spec
deliberately does not batch. Reads are flat at 7 per chunk regardless of population.

The outcome tables — which users were sent to, which were skipped, and the exact `error`
text on every skipped row — are byte-identical before and after, across all three
scenarios. The `LocalDayWindow` grouping shows up as exactly two `select distinct
user_id` queries, one for +05:30 (Kolkata, Colombo) and one for +05:45 (Kathmandu),
confirming the grouping splits on offset as intended rather than collapsing to one
window or degenerating to one per user.

The single-send path was verified separately, since the harness only drives `sendBulk`:
happy path sends and logs `sent`, missing device logs `No registered device`, quiet
hours and a disabled preference each produce their own reason string, and
`NotificationPreference::forUser()` still returns an unsaved defaults model that inserts
on `save()` — the behaviour the settings controller's lazy row creation depends on.

Note on running any of this: a git worktree has no `vendor/`, and pointing it at the
main checkout's via a junction makes `autoload_psr4.php` resolve `App\` to the *main*
checkout's `app/` — the harness then silently measures unmodified code and reports that
nothing changed. Give the worktree its own `vendor` and `composer dump-autoload` before
trusting a single number out of it.
