<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Store The Odds API event ID on matches for reliable linking
        Schema::table('matches', function (Blueprint $table) {
            $table->string('odds_api_event_id', 64)->nullable()->after('status');
            $table->index('odds_api_event_id');
        });

        // Convert market enum to string for flexibility, add point column for spreads/totals
        // MySQL enum alteration is fragile — safer to convert to varchar.
        DB::statement("ALTER TABLE odds_snapshots MODIFY market VARCHAR(30) NOT NULL");

        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->decimal('point', 6, 2)->nullable()->after('decimal_odds');
        });
    }

    public function down(): void
    {
        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->dropColumn('point');
        });

        DB::statement("ALTER TABLE odds_snapshots MODIFY market ENUM('ats','fts','2plus','match_winner') NOT NULL");

        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['odds_api_event_id']);
            $table->dropColumn('odds_api_event_id');
        });
    }
};
