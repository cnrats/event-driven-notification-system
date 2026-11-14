<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable();
            // Position inside the batch request, so a replayed batch response
            // lists the notifications in the order the client sent them.
            $table->unsignedSmallInteger('batch_index')->nullable();

            $table->string('channel', 16);
            $table->string('priority', 8);
            $table->string('recipient', 320);
            $table->text('content');

            $table->string('status', 16);
            // Delivery attempts made against the provider. Deliberately not
            // Laravel's job attempt counter: see docs/adr/0002.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts');

            $table->string('idempotency_key')->collation('utf8mb4_bin')->nullable()->unique();
            $table->char('request_hash', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('trace_context', 64)->nullable();

            $table->string('provider_message_id')->nullable();
            $table->string('last_error', 500)->nullable();

            // When the user asked for delivery (null = as soon as possible).
            $table->dateTime('scheduled_at', 3)->nullable();
            // When a scheduled or retrying row becomes due for the sweeper.
            $table->dateTime('next_attempt_at', 3)->nullable();
            // Lease held by the worker currently delivering this row.
            $table->dateTime('locked_until', 3)->nullable();
            // Last confirmed push to Redis (null = push never confirmed).
            $table->dateTime('enqueued_at', 3)->nullable();
            $table->dateTime('expires_at', 3);

            $table->dateTime('sent_at', 3)->nullable();
            $table->dateTime('failed_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['status', 'next_attempt_at']);   // sweeper: due rows
            $table->index(['status', 'locked_until']);      // sweeper: expired leases
            $table->index(['status', 'enqueued_at']);       // sweeper: unconfirmed pushes
            $table->index(['status', 'created_at']);        // list filter
            $table->index(['channel', 'created_at']);       // list filter
            $table->index(['batch_id', 'status']);          // batch summary
            $table->index(['created_at', 'id']);            // cursor pagination
            $table->index('sent_at');                       // latency metrics
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
