<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Track signal performance over time
        Schema::create('signal_performance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('season');
            $table->unsignedInteger('round_number');
            $table->string('signal_type', 50);
            $table->decimal('avg_strength_hits', 6, 4)->default(0);
            $table->decimal('avg_strength_misses', 6, 4)->default(0);
            $table->decimal('delta', 6, 4)->default(0); // hit - miss; positive = predictive
            $table->unsignedInteger('sample_size')->default(0);
            $table->timestamps();

            $table->unique(['season', 'round_number', 'signal_type']);
        });

        // Track weight changes with reasoning
        Schema::create('weight_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('season');
            $table->unsignedInteger('after_round');
            $table->json('old_weights');
            $table->json('new_weights');
            $table->json('signal_deltas'); // per-signal delta that drove the change
            $table->decimal('accuracy_before', 5, 2)->nullable();
            $table->decimal('accuracy_after', 5, 2)->nullable();
            $table->decimal('brier_score', 6, 4)->nullable();
            $table->text('reasoning')->nullable(); // human-readable explanation
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_adjustments');
        Schema::dropIfExists('signal_performance_logs');
    }
};
