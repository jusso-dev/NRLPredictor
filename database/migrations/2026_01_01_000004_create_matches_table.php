<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('home_team_id')->constrained('teams');
            $table->foreignId('away_team_id')->constrained('teams');
            $table->string('venue')->nullable();
            $table->dateTime('kickoff_at')->nullable();
            $table->enum('status', ['upcoming', 'live', 'completed'])->default('upcoming');
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->timestamps();

            $table->index(['round_id', 'status']);
            $table->index('kickoff_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
