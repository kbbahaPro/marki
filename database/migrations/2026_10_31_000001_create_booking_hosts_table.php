<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every host attending a booking, organizer included. bookings.host_id
     * stays as the organizer, so the double-booking index keeps working;
     * this table lets co-hosts' bookings block their time too.
     */
    public function up(): void
    {
        Schema::create('booking_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('is_organizer')->default(false);

            $table->unique(['booking_id', 'user_id']);
            $table->index('user_id');
        });

        DB::table('booking_hosts')->insertUsing(
            ['booking_id', 'user_id', 'is_organizer'],
            DB::table('bookings')->select('id', 'host_id', DB::raw('1')),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_hosts');
    }
};
