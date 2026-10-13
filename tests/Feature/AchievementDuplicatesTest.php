<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\AchievementService;
use Database\Seeders\AchievementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AchievementDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_the_seeder_twice_keeps_one_row_per_achievement(): void
    {
        $this->seed(AchievementSeeder::class);
        $this->seed(AchievementSeeder::class);

        $this->assertSame(10, Achievement::count());
        $this->assertSame('Première bouchée', Achievement::where('name', 'First bite')->value('name_fr'));
    }

    public function test_unlocking_twice_keeps_one_unlock(): void
    {
        $this->seed(AchievementSeeder::class);
        $user = $this->makeUser();
        $service = app(AchievementService::class);

        $service->checkAndUnlock($user, 'meals', 1);
        $service->checkAndUnlock($user, 'meals', 1);

        $this->assertSame(1, UserAchievement::where('user_id', $user->id)->count());
    }

    public function test_migration_merges_existing_copies(): void
    {
        $migration = require database_path('migrations/2026_10_13_220000_dedupe_achievements.php');
        $migration->down();

        $user = $this->makeUser();
        $original = $this->achievement('First bite', null);
        $copy = $this->achievement('First bite', 'Première bouchée');
        $other = $this->achievement('First Step', null);

        $this->unlock($user->id, $original, '2026-09-01 10:00:00');
        $this->unlock($user->id, $copy, '2026-09-20 10:00:00');
        $this->unlock($user->id, $copy, '2026-09-21 10:00:00');
        $this->unlock($user->id, $other, '2026-09-02 10:00:00');

        $migration->up();

        $this->assertSame([$original, $other], DB::table('achievements')->orderBy('id')->pluck('id')->all());
        $this->assertSame('Première bouchée', DB::table('achievements')->where('id', $original)->value('name_fr'));

        $unlocks = DB::table('user_achievements')->orderBy('achievement_id')->get();
        $this->assertCount(2, $unlocks);
        $this->assertSame($original, $unlocks[0]->achievement_id);
        $this->assertSame('2026-09-01 10:00:00', $unlocks[0]->unlocked_at);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->achievement('First bite', null);
    }

    private function makeUser(): User
    {
        return User::create(['name' => 'Achiever', 'email' => 'achiever@example.test', 'password' => 'x']);
    }

    private function achievement(string $name, ?string $nameFr): int
    {
        return DB::table('achievements')->insertGetId([
            'name' => $name,
            'name_fr' => $nameFr,
            'description' => $name,
            'target' => 1,
            'type' => 'meals',
        ]);
    }

    private function unlock(int $userId, int $achievementId, string $at): void
    {
        DB::table('user_achievements')->insert([
            'user_id' => $userId,
            'achievement_id' => $achievementId,
            'unlocked_at' => $at,
        ]);
    }
}
