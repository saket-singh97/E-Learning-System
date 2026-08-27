<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `tax_rate`/`tax_label` generalize the hardcoded 18% GST rate
     * (design doc §4 decision 4) — `FeeInvoice.gst_rate`/`gst_amount` stay
     * exactly as they are, every existing invoice already has real GST
     * data. These are the new, tenant-configurable values future invoices
     * read instead, both nullable so an unconfigured tenant falls back to
     * today's hardcoded 18%/"GST" behavior untouched.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->char('default_currency', 3)->default('INR')->after('fee_grace_period_days');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('default_currency');
            $table->string('tax_label')->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['default_currency', 'tax_rate', 'tax_label']);
        });
    }
};
