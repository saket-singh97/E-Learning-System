<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('commission_type', 20);
            $table->decimal('commission_value', 10, 2);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            // Unique per tenant (design doc §3.2), not globally — two
            // different institutes running their own affiliate programs
            // may mint the same code text without colliding.
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_codes');
    }
};
