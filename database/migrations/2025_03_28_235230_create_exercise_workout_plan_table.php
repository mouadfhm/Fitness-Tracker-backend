<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExerciseWorkoutPlanTable extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_workout_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('gym_exercise_id')->constrained()->onDelete('cascade');
            // Additional fields: sets, reps, duration, rest time per exercise
            $table->integer('sets')->nullable();
            $table->integer('reps')->nullable();
            $table->integer('duration')->nullable(); // in seconds if applicable\n            
            $table->integer('rest')->nullable(); // in seconds\n            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_workout_plan');
    }
}
