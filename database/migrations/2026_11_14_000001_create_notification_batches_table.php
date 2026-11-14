<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Binary collation: idempotency keys are opaque and case-sensitive.
            $table->string('idempotency_key')->collation('utf8mb4_bin')->nullable()->unique();
            $table->char('request_hash', 64)->nullable();
            $table->unsignedInteger('total');
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_batches');
    }
};
