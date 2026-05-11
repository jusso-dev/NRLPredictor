<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // For match-level h2h/spreads markets we need to distinguish the home outcome
        // from the away outcome. Before this, both sides were updateOrCreated against the
        // same composite key (match+market+bookmaker) so only the last outcome survived —
        // the h2h odds in the DB were effectively unusable for match-winner prediction.
        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->string('outcome', 16)->nullable()->after('market');
        });

        // Clear stale match-level odds so the next FetchOdds run repopulates correctly.
        DB::table('odds_snapshots')
            ->whereIn('market', ['match_winner', 'spreads', 'totals', 'h2h_lay'])
            ->delete();
    }

    public function down(): void
    {
        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};
