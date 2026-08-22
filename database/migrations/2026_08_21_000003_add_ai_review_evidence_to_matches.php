<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('ai_reviewed_at')->nullable()->after('win_signals');
            $table->unsignedTinyInteger('ai_adjustments_count')->nullable()->after('ai_reviewed_at');
            $table->index('ai_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['ai_reviewed_at']);
            $table->dropColumn(['ai_reviewed_at', 'ai_adjustments_count']);
        });
    }
};
