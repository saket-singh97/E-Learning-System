<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S19 N14 — a null batch_id becomes a general, institute-wide thread
 * (design doc §3.2/§4 decision 5), same "absence of a value turns a
 * feature off" shape batch_geofences (S16) already established. On-delete
 * behaviour widens from cascadeOnDelete to nullOnDelete: a general thread
 * has no batch to be deleted alongside, and a batch-scoped thread whose own
 * batch gets deleted should still exist as an orphaned/general-looking
 * thread rather than vanish — same reasoning fee_invoices.program_id
 * (S13) already applied to its own optional-bundle FK. Raw DDL for the
 * nullability change (doctrine/dbal isn't installed for Schema's
 * ->change(), same precedent that migration's own docblock established);
 * dropForeign()/foreign() for the constraint itself need no doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_threads', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
        });

        DB::statement('ALTER TABLE forum_threads ALTER COLUMN batch_id DROP NOT NULL');

        Schema::table('forum_threads', function (Blueprint $table) {
            $table->foreign('batch_id')->references('id')->on('batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forum_threads', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
        });

        DB::statement('ALTER TABLE forum_threads ALTER COLUMN batch_id SET NOT NULL');

        Schema::table('forum_threads', function (Blueprint $table) {
            $table->foreign('batch_id')->references('id')->on('batches')->cascadeOnDelete();
        });
    }
};
