<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\UserDevice;


class NotificationController extends Controller
{

    public function saveDeviceToken(Request $request)
    {
        $request->validate(['device_token' => 'required']);

        UserDevice::updateOrCreate(
            ['user_id' => Auth::id()],
            ['device_token' => $request->device_token]
        );

        $this->storeReportedTimezone(Auth::id(), $request->input('timezone'));

        return response()->json(['message' => 'Device token saved successfully']);
    }

    /**
     * The app piggybacks its IANA timezone on token registration, which it
     * already performs on login and on every token refresh — exactly the two
     * moments the value can have changed.
     *
     * Validated rather than trusted, and dropped rather than rejected. A junk
     * string here must not 422 the request, because that would also throw away
     * the device token and silently stop notifications for that user
     * altogether. A client sending nonsense loses its timezone update; it does
     * not lose push. Junk reaching the column would be worse still: every
     * scheduler run afterwards would throw inside Carbon and take the entire
     * reminder batch down with it.
     */
    private function storeReportedTimezone(int $userId, mixed $timezone): void
    {
        if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        // Not $user->update(): timezone is deliberately outside $fillable so it
        // cannot be mass-assigned through the profile endpoint.
        User::whereKey($userId)->update(['timezone' => $timezone]);
    }

    public function markOpened(Request $request, NotificationLog $log)
    {
        // Without this check the log id is an enumeration oracle: anyone could
        // walk the id space and mark other users' notifications as opened.
        // 404 rather than 403 so the response does not confirm the row exists.
        if ($log->user_id !== $request->user()->id) {
            abort(404);
        }

        // First tap wins. Re-opening the same notification should not move the
        // timestamp, or time-to-open becomes meaningless.
        if ($log->opened_at === null) {
            $log->update(['opened_at' => now()]);
        }

        // Opening a notification is engagement, so it resets the backoff even
        // if the user logs nothing afterwards. Someone still tapping is not
        // someone to go quiet on — that is the case for reminders working.
        // Unlike the model observer this cannot ride along on a write, so it is
        // spelled out here.
        User::whereKey($request->user()->id)->update(['last_engaged_at' => now()]);

        return response()->noContent();
    }
}
