<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('razorpay');
            $table->string('provider_order_id');
            $table->string('provider_payment_id')->nullable();
            $table->string('idempotency_key');
            $table->decimal('amount', 10, 2);
            $table->string('status', 20);
            $table->string('failure_reason')->nullable();
            $table->timestamp('webhook_verified_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
    }
};
