<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-flight log rows used to claim status='success' before the job
     * finished; a killed worker left them lying as phantom successes.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE data_fetch_logs MODIFY status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running'");

            return;
        }

        // sqlite (tests): recreate as a plain string, dropping the old
        // enum check constraint.
        Schema::table('data_fetch_logs', function (Blueprint $table) {
            $table->string('status')->default('running')->change();
        });
    }

    public function down(): void
    {
        DB::table('data_fetch_logs')->where('status', 'running')->update(['status' => 'failed']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE data_fetch_logs MODIFY status ENUM('success', 'failed') NOT NULL DEFAULT 'success'");

            return;
        }

        Schema::table('data_fetch_logs', function (Blueprint $table) {
            $table->string('status')->default('success')->change();
        });
    }
};
