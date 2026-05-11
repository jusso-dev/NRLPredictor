<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('try_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('minute')->nullable();
            $table->timestamps();

            $table->index(['match_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('try_events');
    }
};
