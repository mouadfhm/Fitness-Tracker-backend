<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use RuntimeException;
use Tests\TestCase;

/**
 * Bulk sends go out one send() at a time.
 *
 * They used to go through sendAll(), which posts to FCM's batch endpoint, and
 * Google no longer serves it: every bulk reminder failed with a 404. What these
 * tests pin down is that the bulk path only ever calls send(), and that one
 * bad token or one failed send is recorded against its own user without
 * stopping the others.
 */
class BulkSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_recipient_is_sent_to_with_send_and_logged_as_sent(): void
    {
        $fake = $this->fakeFirebase();
        [$a, $b] = $this->usersWithDevices(2);

        $result = (new NotificationService())->sendBulk(
            [$a->id, $b->id], 'Title', 'Body', NotificationLog::TYPE_MEAL_REMINDER
        );

        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertCount(2, $fake->sent);
        $this->assertSame(2, NotificationLog::where('status', NotificationLog::STATUS_SENT)->count());
    }

    public function test_a_stale_token_is_dropped_and_does_not_stop_the_rest(): void
    {
        $fake = $this->fakeFirebase(['token-1' => new NotFound('Requested entity was not found.')]);
        [$a, $b] = $this->usersWithDevices(2);

        $result = (new NotificationService())->sendBulk(
            [$a->id, $b->id], 'Title', 'Body', NotificationLog::TYPE_MEAL_REMINDER
        );

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(2, $fake->sent);
        $this->assertDatabaseMissing('user_devices', ['device_token' => 'token-1']);
        $this->assertDatabaseHas('user_devices', ['device_token' => 'token-2']);
        $this->assertSame(
            NotificationLog::STATUS_FAILED,
            NotificationLog::where('user_id', $a->id)->value('status')
        );
        $this->assertSame(
            NotificationLog::STATUS_SENT,
            NotificationLog::where('user_id', $b->id)->value('status')
        );
    }

    public function test_any_other_failure_is_logged_and_keeps_the_token(): void
    {
        $this->fakeFirebase(['token-1' => new RuntimeException('FCM is having a moment')]);
        [$a, $b] = $this->usersWithDevices(2);

        $result = (new NotificationService())->sendBulk(
            [$a->id, $b->id], 'Title', 'Body', NotificationLog::TYPE_MEAL_REMINDER
        );

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('user_devices', ['device_token' => 'token-1']);
        $this->assertSame(
            NotificationLog::STATUS_FAILED,
            NotificationLog::where('user_id', $a->id)->value('status')
        );
    }

    /**
     * Binds a stand-in for the Firebase client. It has no sendAll() on purpose:
     * if the bulk path ever goes back to the batch endpoint, these tests fail
     * with a call to an undefined method rather than passing quietly.
     *
     * @param array<string,\Throwable> $failures token => what send() throws for it
     */
    private function fakeFirebase(array $failures = []): object
    {
        $fake = new class($failures) {
            public array $sent = [];

            public function __construct(private array $failures) {}

            public function send($message): array
            {
                $token = $message instanceof CloudMessage
                    ? ($message->jsonSerialize()['token'] ?? null)
                    : null;
                $this->sent[] = $token;

                if ($token !== null && isset($this->failures[$token])) {
                    throw $this->failures[$token];
                }

                return [];
            }
        };

        $this->app->instance('firebase', $fake);

        return $fake;
    }

    /**
     * @return User[]
     */
    private function usersWithDevices(int $count): array
    {
        $users = [];

        for ($i = 1; $i <= $count; $i++) {
            $user = User::create([
                'name'     => "User $i",
                'email'    => "user$i@example.test",
                'password' => 'secret-password',
            ]);

            UserDevice::create(['user_id' => $user->id, 'device_token' => "token-$i"]);

            $users[] = $user;
        }

        return $users;
    }
}
