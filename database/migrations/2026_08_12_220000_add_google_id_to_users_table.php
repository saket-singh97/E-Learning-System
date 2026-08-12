<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same partial-index shape as users_tenant_id_email_unique (S1) — a
     * plain unique(tenant_id, google_id) would still block a soft-deleted
     * row's google_id from ever being reused. Not globally unique: the same
     * Google account can plausibly be a student in one tenant and an
     * instructor in another, same as email is only unique per tenant, not
     * across the whole table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->after('two_factor_recovery_codes');
        });

        DB::statement('create unique index users_tenant_id_google_id_unique on users (tenant_id, google_id) where deleted_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index users_tenant_id_google_id_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_id');
        });
    }
};
