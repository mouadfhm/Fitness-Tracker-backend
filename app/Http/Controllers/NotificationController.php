<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\UserDevice;


class NotificationController extends Controller
{

    public function saveDeviceToken(Request $request)
    {
        $request->validate(['device_token' => 'required']);

        UserDevice::updateOrCreate(
            ['user_id' => auth::id()],
            ['device_token' => $request->device_token]
        );

        return response()->json(['message' => 'Device token saved successfully']);
    }
}
