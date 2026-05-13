<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_alerts', function (Blueprint $table) {
            $table->id();
            // Stable string code so we can detect duplicates / resolve later.
            // e.g. 'model_trails_market', 'calibration_drift', 'no_market_data'.
            $table->string('type', 64);
            $table->string('severity', 16)->default('warning');
            $table->text('message');
            // Round numbers, metric values, etc. that justify the alert.
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'resolved_at']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_alerts');
    }
};
