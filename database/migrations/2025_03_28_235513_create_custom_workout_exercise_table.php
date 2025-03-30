<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('custom_workout_exercise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_workout_id')->constrained()->onDelete('cascade');
            $table->foreignId('gym_exercise_id')->constrained()->onDelete('cascade');
            $table->integer('sets')->nullable();
            $table->integer('reps')->nullable();
            $table->integer('duration')->nullable();
            $table->integer('rest')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_workout_exercise');
    }
};
