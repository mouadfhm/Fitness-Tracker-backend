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
        Schema::create('workout_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // Could reference a workout plan or custom workout if needed\n            
            $table->unsignedBigInteger('workout_id')->nullable();
            $table->timestamp('workout_date');
            $table->integer('duration')->nullable(); // duration in minutes\n            
            $table->integer('calories_burned')->nullable();
            $table->json('details')->nullable(); // detailed log data (exercises performed, etc.)\n            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_logs');
    }
};
