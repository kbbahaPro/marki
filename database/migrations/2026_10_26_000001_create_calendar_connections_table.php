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
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // "google" today; "microsoft" can be added without schema changes.
            $table->string('provider', 20);
            $table->string('account_email');
            // Encrypted with APP_KEY via Eloquent's "encrypted" cast.
            $table->text('access_token');
            $table->text('refresh_token');
            $table->dateTime('token_expires_at');
            $table->text('scopes');
            // Calendar that new events are written to, and calendars checked for busy times.
            $table->string('write_calendar_id')->default('primary');
            $table->json('busy_calendar_ids')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('last_error')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->text('sync_error')->nullable()->after('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('sync_error');
        });

        Schema::dropIfExists('calendar_connections');
    }
};
