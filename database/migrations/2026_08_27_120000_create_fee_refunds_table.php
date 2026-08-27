<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reason');
            $table->string('status', 20)->default('pending');
            $table->string('gateway_refund_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_refunds');
    }
};
