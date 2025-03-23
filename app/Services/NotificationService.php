namespace App\Services;
<?php
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use App\Models\UserDevice;

class NotificationService
{
    public function sendNotification($userId, $title, $body)
    {
        $device = UserDevice::where('user_id', $userId)->first();
        if (!$device) return;

        $messaging = app('firebase');
        $message = CloudMessage::withTarget('token', $device->device_token)
            ->withNotification(Notification::create($title, $body));

        $messaging->send($message);
    }
}