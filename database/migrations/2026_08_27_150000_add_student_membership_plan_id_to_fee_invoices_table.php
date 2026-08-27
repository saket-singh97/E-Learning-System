<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable, additive — same "no single batch/enrolment" shape
     * program_id already established (S13 B17): a membership invoice has
     * no batch/enrolment of its own either, it's routed by which
     * StudentMembershipPlan it's for. batch_id/enrolment_id are already
     * nullable since that migration, so no further ALTER is needed here.
     */
    public function up(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {
            $table->foreignId('student_membership_plan_id')->nullable()->after('program_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_membership_plan_id');
        });
    }
};
