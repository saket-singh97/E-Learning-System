<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * tenant_id is nullable on purpose, unlike role_assignment_audits'
     * (S1) — the actor here is always a super_admin, who has no tenant of
     * their own (Route::domain has no equivalent for the bare Super panel
     * host), so this model does not use BelongsToTenant: that trait's
     * creating() hook unconditionally reads CurrentTenant::id(), which
     * throws when unbound — exactly the actor's own normal state. tenant_id
     * is set explicitly from the *target* user's own tenant_id instead.
     */
    public function up(): void
    {
        Schema::create('impersonation_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_audits');
    }
};
