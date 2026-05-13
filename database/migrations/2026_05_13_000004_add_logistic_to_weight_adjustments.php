<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weight_adjustments', function (Blueprint $table) {
            // Fitted logistic regression coefficients from this round's grader.
            // sigmoid(b0 + b1 * score/100) maps a prediction's normalised score
            // to a calibrated try probability. Persisted so PredictionScorer
            // can reuse the latest fit at write time without refitting per call.
            $table->decimal('logistic_b0', 8, 4)->nullable()->after('graded_predictions');
            $table->decimal('logistic_b1', 8, 4)->nullable()->after('logistic_b0');
            // Number of (score, was_hit) pairs the fit was trained on. Lets the
            // UI tell users whether the calibration is data-thin or robust.
            $table->unsignedInteger('logistic_samples')->nullable()->after('logistic_b1');
        });
    }

    public function down(): void
    {
        Schema::table('weight_adjustments', function (Blueprint $table) {
            $table->dropColumn(['logistic_b0', 'logistic_b1', 'logistic_samples']);
        });
    }
};
