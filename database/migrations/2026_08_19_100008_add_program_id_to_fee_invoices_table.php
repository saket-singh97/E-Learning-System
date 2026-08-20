<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable FK, same "every other optional linkage" shape coupon_id
     * already uses (design doc §3.2 decision 4). A program-linked invoice
     * has no single batch/enrolment — it's one invoice for a bundle of
     * several — so batch_id/enrolment_id (NOT NULL since B1) have to
     * relax to nullable too; doctrine/dbal isn't installed for Schema's
     * ->change(), so this is raw DDL, same as every other Postgres-specific
     * ALTER in this app's migrations (e.g. the lessons_type_check CHECK
     * constraint, S9 B6).
     */
    public function up(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        DB::statement('ALTER TABLE fee_invoices ALTER COLUMN batch_id DROP NOT NULL');
        DB::statement('ALTER TABLE fee_invoices ALTER COLUMN enrolment_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fee_invoices ALTER COLUMN batch_id SET NOT NULL');
        DB::statement('ALTER TABLE fee_invoices ALTER COLUMN enrolment_id SET NOT NULL');

        Schema::table('fee_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
        });
    }
};
