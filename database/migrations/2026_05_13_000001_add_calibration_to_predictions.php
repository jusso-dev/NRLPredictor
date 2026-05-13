<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            // Calibrated probability (0..1) derived from rank-conditioned historical
            // hit rate. Filled by CalibrationGrader once the round completes.
            $table->decimal('model_prob', 5, 4)->nullable()->after('score');

            // Median bookmaker-implied try-scorer probability for this player at
            // grading time. Lets us compare model vs market head-to-head.
            $table->decimal('market_prob', 5, 4)->nullable()->after('model_prob');

            // 1 if the player scored a try in this match, 0 otherwise, null if
            // not yet graded. Indexed for accuracy queries.
            $table->boolean('was_hit')->nullable()->after('market_prob');

            $table->index(['match_id', 'was_hit']);
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->dropIndex(['match_id', 'was_hit']);
            $table->dropColumn(['model_prob', 'market_prob', 'was_hit']);
        });
    }
};
