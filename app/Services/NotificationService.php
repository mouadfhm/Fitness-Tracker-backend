<?php
namespace App\Services;

use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Illuminate\Support\Facades\Log;
use App\Models\UserDevice;
use Throwable;

class NotificationService
{
    public function sendNotification($userId, $title, $body)
    {
        $device = UserDevice::where('user_id', $userId)->first();
        if (!$device) return;

        $message = CloudMessage::withTarget('token', $device->device_token)
            ->withNotification(Notification::create($title, $body))
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
        } catch (Throwable $e) {
            // Never let a failed push break the request that triggered it
            // (unlocking an achievement, logging a meal).
            Log::error('FCM send failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
