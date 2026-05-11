<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_team_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->unsignedTinyInteger('position_number');
            $table->enum('role', ['starting', 'interchange', 'reserve'])->default('starting');
            $table->boolean('is_confirmed')->default(false);
            $table->timestamps();

            $table->unique(['match_id', 'player_id']);
            $table->index(['match_id', 'team_id', 'position_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_team_lists');
    }
};
