<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S12 B13 (design doc §3.2, V3) — the S11 billing-domain equivalent of
 * fee_invoices' own gap: a real subscription hard-deleted by a mis-click
 * had no recovery path before this block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
