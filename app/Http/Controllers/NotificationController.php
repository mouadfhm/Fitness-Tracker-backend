<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\NotificationLog;
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

        return response()->json(['message' => 'Device token saved successfully']);
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

        return response()->noContent();
    }
}
