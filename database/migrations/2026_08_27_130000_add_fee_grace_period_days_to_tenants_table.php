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
            // configured a grace period, so LessonAccess never blocks
            // access on an overdue instalment for that tenant (feature off
            // by default), same shape refund_window_days already took.
            $table->unsignedInteger('fee_grace_period_days')->nullable()->after('refund_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('fee_grace_period_days');
        });
    }
};
