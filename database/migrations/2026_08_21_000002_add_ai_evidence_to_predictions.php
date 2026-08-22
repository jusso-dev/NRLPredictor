<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->unsignedTinyInteger('statistical_score')->nullable()->after('score');
            $table->unsignedTinyInteger('statistical_rank')->nullable()->after('rank_in_match');
            $table->json('ai_cited_signals')->nullable()->after('ai_reasoning');
            $table->unsignedTinyInteger('ai_adjusted_score')->nullable()->after('ai_cited_signals');
            $table->timestamp('ai_reviewed_at')->nullable()->after('ai_adjusted_score');

            $table->index(['match_id', 'statistical_rank']);
            $table->index('ai_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->dropIndex(['match_id', 'statistical_rank']);
            $table->dropIndex(['ai_reviewed_at']);
            $table->dropColumn([
                'statistical_score',
                'statistical_rank',
                'ai_cited_signals',
                'ai_adjusted_score',
                'ai_reviewed_at',
            ]);
        });
    }
};
