<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('url', 512)->unique();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->json('team_tags')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('fetched_at')->nullable();
            $table->timestamps();

            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
