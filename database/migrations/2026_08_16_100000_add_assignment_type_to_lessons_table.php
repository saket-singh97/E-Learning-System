<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * lessons.type is a Postgres CHECK constraint (Laravel's enum() on
     * pgsql compiles to one, not a native ENUM type) — there's no
     * ALTER TYPE ADD VALUE for it. Constraint name confirmed against the
     * live schema (pg_constraint) as lessons_type_check before writing
     * this, not assumed from Laravel's naming convention alone.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE lessons DROP CONSTRAINT lessons_type_check');
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_type_check CHECK (type IN ('video', 'pdf', 'text', 'embed', 'quiz', 'live_session', 'assignment'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lessons DROP CONSTRAINT lessons_type_check');
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_type_check CHECK (type IN ('video', 'pdf', 'text', 'embed', 'quiz', 'live_session'))");
    }
};
