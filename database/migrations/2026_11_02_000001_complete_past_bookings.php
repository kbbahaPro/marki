<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bookings now become "completed" after they end (bookings:complete).
     * Older ones are updated here directly, without events, so the first
     * run does not send a webhook for every past meeting.
     */
    public function up(): void
    {
        DB::table('bookings')
            ->where('status', 'confirmed')
            ->where('end_at', '<=', now())
            ->update(['status' => 'completed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('bookings')
            ->whereIn('status', ['completed', 'no_show'])
            ->update(['status' => 'confirmed']);
    }
};
