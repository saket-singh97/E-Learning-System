<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('device_webhook_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Nullable — null means "any batch" (design doc §3.2), the
            // common case of one device covering the whole institute.
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('device_label');
            $table->string('webhook_secret');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_webhook_credentials');
    }
};
