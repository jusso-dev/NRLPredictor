<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_team_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->enum('side', ['home', 'away']);

            // Possession & completions
            $table->unsignedTinyInteger('possession_pct')->nullable();
            $table->unsignedSmallInteger('completion_numerator')->nullable();
            $table->unsignedSmallInteger('completion_denominator')->nullable();

            // Attack
            $table->unsignedSmallInteger('all_runs')->nullable();
            $table->unsignedSmallInteger('all_run_metres')->nullable();
            $table->unsignedSmallInteger('post_contact_metres')->nullable();
            $table->unsignedSmallInteger('line_breaks')->nullable();
            $table->unsignedSmallInteger('tackle_breaks')->nullable();
            $table->unsignedSmallInteger('offloads')->nullable();

            // Kicking
            $table->unsignedSmallInteger('kicks')->nullable();
            $table->unsignedSmallInteger('kicking_metres')->nullable();
            $table->unsignedTinyInteger('forced_drop_outs')->nullable();

            // Defence
            $table->decimal('effective_tackle_pct', 5, 2)->nullable();
            $table->unsignedSmallInteger('tackles_made')->nullable();
            $table->unsignedSmallInteger('missed_tackles')->nullable();

            // Negative play
            $table->unsignedTinyInteger('errors')->nullable();
            $table->unsignedTinyInteger('penalties_conceded')->nullable();
            $table->unsignedTinyInteger('ruck_infringements')->nullable();

            $table->timestamps();

            $table->unique(['match_id', 'team_id']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_team_stats');
    }
};
