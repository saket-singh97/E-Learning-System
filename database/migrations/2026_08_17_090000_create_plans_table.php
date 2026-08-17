<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('seat_limit')->nullable();
            $table->unsignedInteger('storage_limit_gb');
            $table->unsignedInteger('bandwidth_limit_gb_per_day');
            $table->decimal('ai_spend_cap_usd_per_day', 8, 2);
            $table->decimal('price_inr_per_month', 10, 2);
            $table->string('razorpay_plan_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
