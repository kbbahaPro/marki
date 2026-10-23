<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Times of day are stored as minutes since local midnight (0–1440) in the
     * schedule's time zone. They are only turned into UTC instants for a given
     * date, which keeps DST handling in one place (the slot engine).
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone', 64);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });

        Schema::create('schedule_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            // 0 = Sunday … 6 = Saturday (Carbon::dayOfWeek).
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');

            $table->index(['schedule_id', 'weekday']);
        });

        Schema::create('schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Both null means the whole day is unavailable.
            $table->unsignedSmallInteger('start_minute')->nullable();
            $table->unsignedSmallInteger('end_minute')->nullable();

            $table->index(['schedule_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_overrides');
        Schema::dropIfExists('schedule_rules');
        Schema::dropIfExists('schedules');
    }
};
