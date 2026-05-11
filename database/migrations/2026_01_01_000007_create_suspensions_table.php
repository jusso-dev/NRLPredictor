<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('offence')->nullable();
            $table->unsignedTinyInteger('games_remaining')->default(0);
            $table->unsignedSmallInteger('round_available')->nullable();
            $table->timestamps();

            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspensions');
    }
};
