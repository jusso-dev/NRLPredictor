<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_opponent_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opponent_team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('games')->default(0);
            $table->unsignedSmallInteger('tries')->default(0);
            $table->decimal('try_rate', 5, 3)->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'opponent_team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_opponent_stats');
    }
};
