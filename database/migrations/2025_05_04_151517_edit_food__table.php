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
        //add added_by and added_at columns to food table
        Schema::table('foods', function (Blueprint $table) {
            $table->unsignedBigInteger('added_by')->nullable()->after('id');
            $table->foreign('added_by')->references('id')->on('users')->onDelete('cascade');
        }) ;

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
