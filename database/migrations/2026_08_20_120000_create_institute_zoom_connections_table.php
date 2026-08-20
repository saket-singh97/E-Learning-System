<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institute_zoom_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // One row per tenant (design doc decision 2) — an institute
            // admin connects their own Zoom account once.
            $table->unique('tenant_id');
            $table->string('account_id');
            // text, not string — Laravel's `encrypted` cast produces a much
            // longer ciphertext than the raw token.
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at');
            $table->foreignId('connected_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institute_zoom_connections');
    }
};
