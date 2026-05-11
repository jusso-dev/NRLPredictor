<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('nrl_slug')->unique();
            $table->string('name');
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('position', [
                'fullback', 'winger', 'centre', 'five-eighth', 'halfback',
                'prop', 'hooker', 'second-row', 'lock',
            ])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->unsignedInteger('career_games')->default(0);
            $table->unsignedInteger('career_tries')->default(0);
            $table->unsignedInteger('career_try_assists')->default(0);
            $table->unsignedInteger('career_line_breaks')->default(0);
            $table->unsignedInteger('current_season_games')->default(0);
            $table->unsignedInteger('current_season_tries')->default(0);
            $table->decimal('current_season_try_rate', 5, 3)->default(0);
            $table->timestamps();

            $table->index(['team_id', 'position']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
