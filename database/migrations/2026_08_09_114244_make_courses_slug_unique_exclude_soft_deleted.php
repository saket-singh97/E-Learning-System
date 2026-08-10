<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plain unique(tenant_id, slug) still matches soft-deleted rows, so
     * deleting a course and recreating one with the same slug fails with a
     * duplicate-key error. Swap it for a partial index that only enforces
     * uniqueness among non-deleted rows.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'slug']);
        });

        DB::statement('create unique index courses_tenant_id_slug_unique on courses (tenant_id, slug) where deleted_at is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index courses_tenant_id_slug_unique');

        Schema::table('courses', function (Blueprint $table) {
            $table->unique(['tenant_id', 'slug']);
        });
    }
};
