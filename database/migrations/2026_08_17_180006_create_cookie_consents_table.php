<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * tenant_id and user_id both nullable — a consent can be recorded on
     * the login page before any tenant resolves and before any user
     * exists, then backfilled once the visitor authenticates (design doc
     * §4 decision 9). Not BelongsToTenant for the same reason
     * audit_log_entries isn't.
     */
    public function up(): void
    {
        Schema::create('cookie_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id');
            $table->jsonb('categories');
            $table->timestamp('consented_at');

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_consents');
    }
};
