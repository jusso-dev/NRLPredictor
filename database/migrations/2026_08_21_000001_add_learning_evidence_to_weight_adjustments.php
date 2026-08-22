<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weight_adjustments', function (Blueprint $table) {
            $table->unsignedSmallInteger('validation_matches')->nullable()->after('graded_predictions');
            $table->decimal('ndcg_before', 6, 4)->nullable()->after('validation_matches');
            $table->decimal('ndcg_after', 6, 4)->nullable()->after('ndcg_before');
            $table->boolean('candidate_accepted')->nullable()->after('ndcg_after');
            $table->decimal('learning_step', 5, 4)->nullable()->after('candidate_accepted');
            $table->json('pattern_insights')->nullable()->after('learning_step');
        });
    }

    public function down(): void
    {
        Schema::table('weight_adjustments', function (Blueprint $table) {
            $table->dropColumn([
                'validation_matches',
                'ndcg_before',
                'ndcg_after',
                'candidate_accepted',
                'learning_step',
                'pattern_insights',
            ]);
        });
    }
};
