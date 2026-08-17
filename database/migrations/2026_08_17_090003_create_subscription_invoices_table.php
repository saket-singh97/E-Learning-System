<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->constrained()->cascadeOnDelete();
            // Snapshot, not a live reference — a later plan change must not
            // rewrite what an already-issued invoice billed for.
            $table->foreignId('plan_id')->constrained('plans');
            $table->decimal('amount', 10, 2);
            $table->string('currency')->default('INR');
            $table->string('kind')->default('recurring');
            $table->string('status')->default('pending');
            $table->string('razorpay_payment_id')->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
