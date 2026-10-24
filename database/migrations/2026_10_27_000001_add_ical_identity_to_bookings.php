<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A rescheduled booking is a new row, but guests' calendar apps must see
     * the same event moving: it keeps the original iCalendar UID and bumps
     * the SEQUENCE.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('ical_uid')->nullable()->after('uid');
            $table->unsignedInteger('ical_sequence')->default(0)->after('ical_uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['ical_uid', 'ical_sequence']);
        });
    }
};
