<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WeeklyCyclePlan;
use App\Models\ScheduledWorkout;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;


class WeeklyCyclePlanController extends Controller
{
public function index()
{
    $user = Auth::user();
    $weeks = [];

    $workouts = ScheduledWorkout::with('workout')
        ->where('user_id', $user->id)
        ->orderBy('scheduled_at')
        ->get();

    foreach ($workouts as $scheduledWorkout) {
        $date = Carbon::parse($scheduledWorkout->scheduled_at);

        // Start of the week (Monday)
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        if (!isset($weeks[$weekStart])) {
            $weeks[$weekStart] = [
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
                'days' => []
            ];

            // Initialize all days of the week
            for ($i = 0; $i < 7; $i++) {
                $day = Carbon::parse($weekStart)->addDays($i);
                $key = strtolower($day->format('D'));

                $weeks[$weekStart]['days'][$key] = [
                    'date' => $day->toDateString(),
                    'workouts' => []
                ];
            }
        }

        $dayKey = strtolower($date->format('D'));

        $weeks[$weekStart]['days'][$dayKey]['workouts'][] = $scheduledWorkout;
    }

    return response()->json([
        'weeks' => array_values($weeks)
    ]);
}

    public function store(Request $request)
    {
        $data = $request->validate([
            'start_date'   => 'required|date',
            'weeks'        => 'required|integer|min:1',
            'days_pattern' => 'required|array', // e.g., {"mon": 1, "tue": 2, "wed": null, "thu": 1, "fri": 2, "sat": null, "sun": null}
        ]);

        $data['user_id'] = Auth::id();
        $cyclePlan = WeeklyCyclePlan::create($data);

        // Optionally: Generate scheduled workout dates based on this cycle plan.
        $scheduledDates = $this->generateSchedule($cyclePlan);

        return response()->json([
            'cycle_plan'      => $cyclePlan,
            'scheduled_dates' => $scheduledDates,
        ], 201);
    }
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'start_date'   => 'sometimes|date',
            'weeks'        => 'sometimes|integer|min:1',
            'days_pattern' => 'sometimes|array', // e.g., {"mon": 1, "tue": 2, "wed": null, "thu": 1, "fri": 2, "sat": null, "sun": null}
        ]);

        $cyclePlan = WeeklyCyclePlan::where('id', $id)->where('user_id', Auth::id())->firstOrFail();
        unset($data['user_id']);
        $cyclePlan->update($data);

        // Optionally: Generate scheduled workout dates based on this cycle plan.
        $scheduledDates = $this->generateSchedule($cyclePlan);

        return response()->json([
            'cycle_plan'      => $cyclePlan,
            'scheduled_dates' => $scheduledDates,
        ], 200);
    }

    /**
     * Generate scheduled workout dates for the given cycle plan.
     *
     * @param WeeklyCyclePlan $cyclePlan
     * @return array
     */
    private function generateSchedule(WeeklyCyclePlan $cyclePlan)
    {
        $dates = [];
        $startDate = Carbon::parse($cyclePlan->start_date);
        $daysPattern = $cyclePlan->days_pattern; // associative array e.g., ['mon' => true, 'tue' => true, ...]
        $cycleWeeks = $cyclePlan->weeks;

        // Map weekday numbers (0 for Sunday, 1 for Monday, ...) to keys in daysPattern
        $weekdayMap = [
            0 => 'sun',
            1 => 'mon',
            2 => 'tue',
            3 => 'wed',
            4 => 'thu',
            5 => 'fri',
            6 => 'sat',
        ];


        $scheduled = [];
        for ($week = 0; $week < $cycleWeeks; $week++) {
            for ($day = 0; $day < 7; $day++) {
                $currentDate = $startDate->copy()->addWeeks($week)->addDays($day);
                $dayKey = $weekdayMap[$currentDate->dayOfWeek];
                if (isset($daysPattern[$dayKey]) && !empty($daysPattern[$dayKey])) {
                    $workoutId = $daysPattern[$dayKey]; // e.g., the workout plan ID
                    // Create a scheduled workout record
                    $scheduledWorkout = ScheduledWorkout::create([
                        'user_id'    => $cyclePlan->user_id,
                        'workout_id' => $workoutId,
                        'scheduled_at' => $currentDate->toDateTimeString(),
                    ]);
                    $scheduled[] = $scheduledWorkout;
                }
            }
        }

        return $scheduled;
    }
}
