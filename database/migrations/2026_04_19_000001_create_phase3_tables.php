<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §3.1 Edge/side-of-field try distribution
        Schema::create('team_try_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->enum('period', ['last_5', 'last_10', 'season']);
            $table->unsignedSmallInteger('attack_left_pct')->default(0);
            $table->unsignedSmallInteger('attack_middle_pct')->default(0);
            $table->unsignedSmallInteger('attack_right_pct')->default(0);
            $table->unsignedSmallInteger('concede_left_pct')->default(0);
            $table->unsignedSmallInteger('concede_middle_pct')->default(0);
            $table->unsignedSmallInteger('concede_right_pct')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'period']);
        });

        // §3.3 Referees
        Schema::create('referees', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('games_officiated')->default(0);
            $table->decimal('paa', 5, 2)->default(0); // Penalties Above Average
            $table->decimal('sraa', 5, 2)->default(0); // Sin-bins/Reports Above Average
            $table->decimal('avg_penalties_per_game', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('referee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('referee_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['referee', 'touch_judge', 'video_referee'])->default('referee');
            $table->timestamps();

            $table->unique(['match_id', 'referee_id', 'role']);
        });

        // §3.4 Weather
        Schema::create('weather_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->decimal('temp_c', 4, 1)->nullable();
            $table->decimal('rainfall_mm_6h', 5, 1)->nullable();
            $table->unsignedTinyInteger('humidity_pct')->nullable();
            $table->unsignedSmallInteger('wind_kph')->nullable();
            $table->boolean('is_wet')->default(false);
            $table->boolean('is_hot')->default(false);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
        });

        // §3.6 Player club history
        Schema::create('player_club_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('seasons')->nullable(); // e.g. "2020-2023"
            $table->unsignedInteger('games')->default(0);
            $table->unsignedInteger('tries')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'team_id']);
        });

        // §3.8 Odds snapshots
        Schema::create('odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('market', ['ats', 'fts', '2plus', 'match_winner']);
            $table->string('bookmaker', 50);
            $table->decimal('decimal_odds', 8, 2);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['match_id', 'market']);
        });

        // §3.2 Milestone events
        Schema::create('milestone_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->enum('type', ['try_milestone', 'game_milestone', 'record_chase', 'themed_round']);
            $table->unsignedInteger('current_count')->default(0);
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedSmallInteger('distance')->default(0);
            $table->timestamps();

            $table->index(['player_id', 'match_id']);
        });

        // §3.5 Add debut/return columns to match_team_lists
        Schema::table('match_team_lists', function (Blueprint $table) {
            $table->boolean('is_debut')->default(false)->after('is_confirmed');
            $table->boolean('is_long_return')->default(false)->after('is_debut');
            $table->boolean('is_returning')->default(false)->after('is_long_return');
        });

        // §3.7 Add turnaround columns to matches
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('days_since_last_home')->nullable()->after('win_signals');
            $table->unsignedTinyInteger('days_since_last_away')->nullable()->after('days_since_last_home');
            $table->boolean('interstate_travel_home')->default(false)->after('days_since_last_away');
            $table->boolean('interstate_travel_away')->default(false)->after('interstate_travel_home');
            $table->boolean('tactical_shift_home')->default(false)->after('interstate_travel_away');
            $table->boolean('tactical_shift_away')->default(false)->after('tactical_shift_home');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn([
                'days_since_last_home', 'days_since_last_away',
                'interstate_travel_home', 'interstate_travel_away',
                'tactical_shift_home', 'tactical_shift_away',
            ]);
        });

        Schema::table('match_team_lists', function (Blueprint $table) {
            $table->dropColumn(['is_debut', 'is_long_return', 'is_returning']);
        });

        Schema::dropIfExists('milestone_events');
        Schema::dropIfExists('odds_snapshots');
        Schema::dropIfExists('player_club_histories');
        Schema::dropIfExists('weather_forecasts');
        Schema::dropIfExists('referee_assignments');
        Schema::dropIfExists('referees');
        Schema::dropIfExists('team_try_distributions');
    }
};
