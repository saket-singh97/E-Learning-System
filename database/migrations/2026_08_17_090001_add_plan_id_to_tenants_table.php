<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Nullable, additive — null means "no plan assigned yet", which
            // falls back to the existing plan_tier + hardcoded caps
            // (BandwidthQuota/AiSpendCap). Never cascades: a plan being
            // removed must not take a tenant row down with it.
            $table->foreignId('plan_id')->nullable()->after('plan_tier')->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
