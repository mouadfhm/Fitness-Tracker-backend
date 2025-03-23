<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\UserAchievement;
use App\Services\NotificationService;
class AchievementService
{
    public function checkAndUnlock($user, $type, $progress)
    {
        $achievements = Achievement::where('type', $type)->get();

        foreach ($achievements as $achievement) {
            if ($progress >= $achievement->target) {
                $this->unlock($user, $achievement);
            }
        }
    }

    private function unlock($user, $achievement)
    {
        if (!UserAchievement::where('user_id', $user->id)->where('achievement_id', $achievement->id)->exists()) {
            UserAchievement::create([
                'user_id' => $user->id,
                'achievement_id' => $achievement->id,
                'unlocked_at' => now(),
            ]);
            // Send notification
            $notificationService = new NotificationService();
            $notificationService->sendNotification(
                $user->id,
                "🎉 Achievement Unlocked!",
                "You've unlocked: " . $achievement->name
            );
        }
    }
}
