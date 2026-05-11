<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('season');
            $table->unsignedSmallInteger('round_number');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['season', 'round_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rounds');
    }
};
