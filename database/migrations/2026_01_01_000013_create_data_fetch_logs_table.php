<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_fetch_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('job_class');
            $table->enum('status', ['success', 'failed'])->default('success');
            $table->unsignedInteger('records_updated')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['job_class', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_fetch_logs');
    }
};
