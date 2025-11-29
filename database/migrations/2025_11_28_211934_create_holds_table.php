<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity')->unsigned();
            $table->timestamp('expires_at')->index(); //    important for the expiry job
            $table->enum('status', ['active', 'expired', 'consumed'])->default('active');
            $table->timestamps();

            // Indexes for performance
            $table->index(['status', 'expires_at']); // for searching expired holds
            $table->index(['product_id', 'status']); // for calculating available stock
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
