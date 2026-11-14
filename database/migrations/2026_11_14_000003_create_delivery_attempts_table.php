<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per call to the provider: the audit trail that answers
        // "why did this notification fail?" without grepping logs.
        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id');
            $table->string('channel', 16);
            $table->unsignedTinyInteger('attempt');
            $table->string('outcome', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['notification_id', 'attempt']);
            $table->index(['created_at', 'outcome']);       // success / failure rates
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
