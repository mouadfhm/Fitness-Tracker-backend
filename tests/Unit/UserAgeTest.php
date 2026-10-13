<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Age now comes from the birthday, so calorie targets move on with it, while
 * accounts that only ever typed an age keep working.
 */
class UserAgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_age_follows_the_birthday(): void
    {
        $user = new User(['age' => 20, 'birth_date' => '1990-10-14']);
        $this->assertSame(35, $user->age);

        Carbon::setTestNow('2026-10-14 08:00:00');
        $this->assertSame(36, $user->age);
    }

    public function test_typed_age_is_the_fallback_without_a_birthday(): void
    {
        $this->assertSame(29, (new User(['age' => 29]))->age);
        $this->assertNull((new User)->age);
    }

    public function test_the_api_returns_both_fields(): void
    {
        $json = (new User(['age' => 20, 'birth_date' => '1990-01-01']))->toArray();
        $this->assertSame(36, $json['age']);
        $this->assertSame('1990-01-01', $json['birth_date']);
    }

    public function test_bmr_uses_the_birthday_age(): void
    {
        $user = new User([
            'weight' => 70, 'height' => 175, 'gender' => 'male',
            'age' => 20, 'birth_date' => '1996-01-01',
        ]);
        // 700 + 1093.75 - 5 * 30 + 5
        $this->assertEqualsWithDelta(1648.75, $user->basalMetabolicRate(), 0.001);
    }

    public function test_a_birthday_refreshes_the_stored_age(): void
    {
        $data = User::syncAgeWithBirthDate(['birth_date' => '2000-05-01', 'age' => 99]);
        $this->assertSame(26, $data['age']);
    }

    public function test_an_old_app_changing_the_age_drops_the_birthday(): void
    {
        $user = new User(['birth_date' => '2000-05-01']);

        $this->assertSame(['age' => 26], User::syncAgeWithBirthDate(['age' => 26], $user));
        $this->assertSame(
            ['age' => 30, 'birth_date' => null],
            User::syncAgeWithBirthDate(['age' => 30], $user),
        );
        $this->assertSame(['age' => 30], User::syncAgeWithBirthDate(['age' => 30], new User(['age' => 25])));
    }
}
