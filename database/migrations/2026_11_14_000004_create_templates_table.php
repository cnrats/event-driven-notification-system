<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            // Null means the template may be used on any channel.
            $table->string('channel', 16)->nullable();
            $table->text('body');
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
