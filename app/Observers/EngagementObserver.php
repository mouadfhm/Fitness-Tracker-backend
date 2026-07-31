<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stamps users.last_engaged_at whenever a user logs something.
 *
 * Registered once per logging model in AppServiceProvider rather than written
 * into the controllers. A controller-side update only covers the endpoints that
 * exist today; a future logging endpoint that forgets the line would make its
 * users look dormant and get them nagged into uninstalling. Observing the model
 * covers every write path to that model, including ones not written yet.
 */
class EngagementObserver
{
    public function created(Model $model): void
    {
        $this->touch($model->user_id);
    }

    private function touch($userId): void
    {
        if (!$userId) {
            return;
        }

        try {
            // Query-builder update rather than $user->update(): no model load,
            // one statement, and it cannot recurse into another observer.
            User::whereKey($userId)->update(['last_engaged_at' => now()]);
        } catch (Throwable $e) {
            // Bookkeeping must never fail the write it is riding along with.
            // Losing one stamp costs at most one extra reminder; failing the
            // request loses the user's logged meal.
            Log::error('Could not stamp last_engaged_at', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
