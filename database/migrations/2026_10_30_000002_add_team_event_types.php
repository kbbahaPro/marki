<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An event type is owned either by one person (user_id) or by a team
     * (team_id); exactly one is set. Team event types list their possible
     * hosts in event_type_hosts.
     */
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->unique(['team_id', 'slug']);
        });

        Schema::create('event_type_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Round robin: fixed hosts attend every booking; the others rotate.
            // Collective: every host is effectively fixed.
            $table->boolean('is_fixed')->default(false);
            // Round robin assignment: higher priority first (0–4), then the
            // fewest recent bookings relative to weight.
            $table->unsignedTinyInteger('priority')->default(2);
            $table->unsignedSmallInteger('weight')->default(100);

            $table->unique(['event_type_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_type_hosts');

        Schema::table('event_types', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'slug']);
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
