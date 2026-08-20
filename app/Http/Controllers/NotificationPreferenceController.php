<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\Request;

/**
 * The settings screen's half of preferences. Enforcement lives in
 * NotificationService; nothing here decides whether a notification goes out.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request)
    {
        // forUser returns defaults rather than 404 for a user who has never
        // saved. The screen wants something to render either way, and "no row"
        // is not an error — it is most users, most of the time.
        return response()->json(
            NotificationPreference::forUser($request->user()->id)->toApiArray()
        );
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'meal_reminders'    => ['sometimes', 'boolean'],
            'workout_reminders' => ['sometimes', 'boolean'],
            'achievements'      => ['sometimes', 'boolean'],
            'winback'           => ['sometimes', 'boolean'],
            'streaks'           => ['sometimes', 'boolean'],

            // `sometimes` with `nullable` is what makes both gestures
            // expressible: omit a key to leave it alone, or send it as null to
            // clear it. Without `nullable` there would be no way to switch quiet
            // hours off at all.
            //
            // H:i is what the app sends. H:i:s is accepted too, because that is
            // the shape a client would have if it ever echoed back a value read
            // from the `time` column.
            'quiet_from'        => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'quiet_to'          => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
        ]);

        $preferences = NotificationPreference::forUser($request->user()->id);
        $preferences->fill($validated);

        // A window needs both ends. Rather than 422 on a half-set pair — which
        // would put the client's error handling in charge of a state it cannot
        // usefully explain to anyone — one missing end is read as "quiet hours
        // off" and both are cleared. Every other reading has to invent the end
        // the user did not give.
        if ($preferences->quiet_from === null || $preferences->quiet_to === null) {
            $preferences->quiet_from = null;
            $preferences->quiet_to = null;
        }

        // The lazily-created row is born here, on the first save. user_id comes
        // from the token and never from the body, so one user cannot write
        // another's settings.
        $preferences->user_id = $request->user()->id;
        $preferences->save();

        return response()->json($preferences->toApiArray());
    }
}
