<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique(); // The primary key for idempotency
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['success', 'failure']);
            $table->json('payload')->nullable(); // Store the full webhook payload
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('order_id');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
