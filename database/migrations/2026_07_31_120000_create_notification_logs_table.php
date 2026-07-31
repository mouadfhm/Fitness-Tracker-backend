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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type');                 // meal_reminder, workout_reminder, achievement, winback
            $table->string('title');
            $table->text('body');
            $table->string('status');               // sent, failed, skipped
            $table->string('error')->nullable();    // failure reason when status = failed
            $table->timestamp('sent_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            // The lookup engagement backoff performs: "how did this user react to
            // the last N notifications of this type?"
            $table->index(['user_id', 'type', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
