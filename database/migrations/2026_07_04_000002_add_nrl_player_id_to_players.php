<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * nrl.com's stable numeric playerId. Name slugs collide (two players can
     * share a name) and drift ("Mitch" vs "Mitchell"); this is the reliable
     * key for matching players across the draw, team-list and stats feeds.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedBigInteger('nrl_player_id')->nullable()->unique()->after('nrl_slug');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('nrl_player_id');
        });
    }
};
