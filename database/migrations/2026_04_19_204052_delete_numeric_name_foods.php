<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('foods')->whereRaw("name REGEXP '^[0-9]+$'")->delete();
    }

    public function down(): void
    {
        // Intentionally empty — deleted corrupt data should not be restored
    }
};
