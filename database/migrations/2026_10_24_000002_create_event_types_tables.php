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
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null means "use the owner's default schedule".
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            // Reserved for round-robin / collective event types later.
            $table->string('scheduling_type', 20)->default('single');
            $table->string('title');
            // Deleted event types get a "--deleted-{id}" suffix so the slug can be reused.
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('slot_interval_minutes')->nullable();
            $table->string('location_type', 20);
            $table->string('location_value', 500)->nullable();
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->unsignedInteger('min_notice_minutes')->default(0);
            $table->unsignedSmallInteger('max_days_ahead')->default(60);
            $table->unsignedSmallInteger('daily_limit')->nullable();
            $table->json('reminder_offsets')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'slug']);
        });

        Schema::create('event_type_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedTinyInteger('position')->default(0);

            $table->index(['event_type_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_type_questions');
        Schema::dropIfExists('event_types');
    }
};
