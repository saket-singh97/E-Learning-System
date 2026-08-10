<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plain unique(tenant_id, email) still matches soft-deleted rows, so a
     * soft-deleted user's email blocks a new registration with the same
     * email under the same tenant. Swap it for a partial index that only
     * enforces uniqueness among non-deleted rows.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
        });

        DB::statement('create unique index users_tenant_id_email_unique on users (tenant_id, email) where deleted_at is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index users_tenant_id_email_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'email']);
        });
    }
};
