<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * tenant_id and actor_id both nullable, same reasoning
     * `impersonation_audits`' own migration already documents: a
     * platform-level action (a super_admin editing a Plan) has no tenant,
     * and a system-initiated sweep (a scheduled job) has no human actor.
     * This model does not use BelongsToTenant for the same reason that
     * migration gives — the trait's creating() hook would throw on every
     * unbound write. actor_id is nullOnDelete, not cascadeOnDelete
     * (deviates from impersonation_audits' own actor_id) — an audit trail
     * entry recording what a now-deleted user once did is exactly the
     * kind of row that must outlive the account, not vanish with it.
     * Append-only: no `updated_at`, an audit entry is never edited.
     */
    public function up(): void
    {
        Schema::create('audit_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_entries');
    }
};
