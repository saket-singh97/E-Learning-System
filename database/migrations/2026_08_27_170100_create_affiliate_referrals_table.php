<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fee_payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            // One attribution per payment — a payment was either referred
            // by exactly one affiliate code or none at all, never two.
            $table->unique('fee_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_referrals');
    }
};
