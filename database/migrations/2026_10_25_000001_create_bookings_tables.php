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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('event_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('host_id')->constrained('users')->restrictOnDelete();

            // All in UTC. blocked_* is the meeting widened by its buffers at booking time.
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->dateTime('blocked_start_at');
            $table->dateTime('blocked_end_at');

            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_phone', 40)->nullable();
            $table->string('guest_timezone', 64);
            $table->text('guest_notes')->nullable();

            $table->string('status', 20)->default('confirmed');
            $table->string('cancelled_by', 10)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('bookings')->nullOnDelete();

            // SHA-256 of the guest's cancel/reschedule token; the raw token is only emailed.
            $table->char('manage_token_hash', 64)->unique();

            // Copied from the event type so later edits do not change past bookings.
            $table->string('location_type', 20);
            $table->string('location_value', 500)->nullable();
            $table->string('meeting_url', 500)->nullable();

            $table->string('external_event_id')->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->timestamps();

            // Backstop against double-booking: at most one confirmed booking per host
            // and start time. Cancelled bookings get NULL here and stop counting.
            $table->dateTime('active_start_at')->nullable()
                ->storedAs("case when status = 'confirmed' then start_at else null end");
            $table->unique(['host_id', 'active_start_at']);

            $table->index(['host_id', 'status', 'blocked_start_at']);
            $table->index(['event_type_id', 'status', 'start_at']);
        });

        Schema::create('booking_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('event_type_questions')->nullOnDelete();
            // Copied so the answer still makes sense if the question is edited or removed.
            $table->string('label');
            $table->text('value')->nullable();
            $table->unsignedTinyInteger('position')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_answers');
        Schema::dropIfExists('bookings');
    }
};
