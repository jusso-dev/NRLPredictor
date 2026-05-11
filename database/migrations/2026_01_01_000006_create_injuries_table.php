<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('injuries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('injury_type')->nullable();
            $table->enum('status', ['out', 'doubt', 'test'])->default('out');
            $table->string('expected_return')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('resolved')->default(false);
            $table->dateTime('fetched_at')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('injuries');
    }
};
