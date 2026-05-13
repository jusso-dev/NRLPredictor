<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('season');
            $table->unsignedInteger('from_round');
            $table->unsignedInteger('to_round');
            $table->boolean('apply')->default(false);

            // pending -> running -> completed | failed
            $table->string('status', 16)->default('pending');

            // Backtester::walkForward result: { rounds: [...], summary: {...} }
            $table->json('result')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_runs');
    }
};
