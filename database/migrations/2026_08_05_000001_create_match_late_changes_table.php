<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_late_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            // out | in | positional | odds_drift
            $table->string('type', 24);
            $table->string('summary', 255);
            $table->json('detail')->nullable();
            $table->string('source', 60)->default('nrl.com');

            // Signed: negative once the match has kicked off.
            $table->integer('minutes_to_kickoff')->nullable();
            $table->timestamp('detected_at');

            // sha1(type|player|summary) — the same change seen on consecutive
            // polls must not stack up as new rows.
            $table->char('fingerprint', 40);
            $table->timestamps();

            $table->index(['match_id', 'detected_at']);
            $table->unique(['match_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_late_changes');
    }
};
