<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('rank_in_match');
            $table->json('signals')->nullable();
            $table->text('ai_reasoning')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['match_id', 'rank_in_match']);
            $table->unique(['match_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
