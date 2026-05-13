<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weight_adjustments', function (Blueprint $table) {
            // Log loss for the model on this round. Lower is better.
            // Penalises confident wrong predictions much harder than Brier.
            $table->decimal('log_loss', 6, 4)->nullable()->after('brier_score');

            // mean(model_prob - market_prob) on hits MINUS the same on misses.
            // Positive = model has alpha beyond just chasing bookmaker odds.
            // Negative = the bookmaker market is doing all the work.
            $table->decimal('value_score', 6, 4)->nullable()->after('log_loss');

            // Brier and log loss using market_prob directly. If our model can't
            // beat these, the rest of the signal stack isn't adding anything.
            $table->decimal('market_brier', 6, 4)->nullable()->after('value_score');
            $table->decimal('market_log_loss', 6, 4)->nullable()->after('market_brier');

            // Sample size that produced the metrics above (top-N graded predictions).
            $table->unsignedInteger('graded_predictions')->nullable()->after('market_log_loss');
        });
    }

    public function down(): void
    {
        Schema::table('weight_adjustments', function (Blueprint $table) {
            $table->dropColumn([
                'log_loss',
                'value_score',
                'market_brier',
                'market_log_loss',
                'graded_predictions',
            ]);
        });
    }
};
