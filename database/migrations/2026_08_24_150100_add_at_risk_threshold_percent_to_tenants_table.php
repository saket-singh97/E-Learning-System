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
        Schema::table('tenants', function (Blueprint $table) {
            // Nullable, additive — null falls back to
            // StudentMetrics::AT_RISK_THRESHOLD's existing hardcoded 60.0
            // default, zero blast radius on every existing tenant.
            $table->decimal('at_risk_threshold_percent', 5, 2)->nullable()->after('plan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('at_risk_threshold_percent');
        });
    }
};
