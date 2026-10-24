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
        Schema::create('booking_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('offset_minutes');
            $table->dateTime('send_at');
            // Claimed atomically by the scheduler, so a reminder is sent at most once.
            $table->dateTime('sent_at')->nullable();

            $table->unique(['booking_id', 'offset_minutes']);
            $table->index(['sent_at', 'send_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            // The guest's manage token, encrypted with APP_KEY, so reminders can
            // include the cancel/reschedule links. Lookups still use the hash.
            $table->text('manage_token')->nullable()->after('manage_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('manage_token');
        });

        Schema::dropIfExists('booking_reminders');
    }
};
