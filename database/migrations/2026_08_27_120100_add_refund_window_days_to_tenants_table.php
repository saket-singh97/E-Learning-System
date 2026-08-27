<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Nullable, additive — null means the institute hasn't
            // configured a refund window yet, so RefundEligibilityService
            // refuses every refund for that tenant (feature off by
            // default), same shape at_risk_threshold_percent already took.
            $table->unsignedInteger('refund_window_days')->nullable()->after('at_risk_threshold_percent');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('refund_window_days');
        });
    }
};
