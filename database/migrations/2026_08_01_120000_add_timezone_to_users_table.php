<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable with no default and no backfill on purpose. A guessed
            // timezone is indistinguishable from a reported one once it sits in
            // the column, so NULL has to stay meaningful: "the device has not
            // told us yet, fall back to Africa/Casablanca". The app reports the
            // real value on the next login or token refresh.
            $table->string('timezone')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
