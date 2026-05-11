<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('home_win_pct')->nullable()->after('away_score');
            $table->unsignedTinyInteger('away_win_pct')->nullable()->after('home_win_pct');
            $table->unsignedBigInteger('predicted_winner_id')->nullable()->after('away_win_pct');
            $table->json('win_signals')->nullable()->after('predicted_winner_id');

            $table->foreign('predicted_winner_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropForeign(['predicted_winner_id']);
            $table->dropColumn(['home_win_pct', 'away_win_pct', 'predicted_winner_id', 'win_signals']);
        });
    }
};
