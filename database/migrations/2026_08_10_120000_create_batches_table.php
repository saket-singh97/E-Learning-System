<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->integer('seat_limit')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->string('self_enrol_mode', 30)->default('manual_only');
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->softDeletes();
        });

        // Partial unique index excluding soft-deleted rows, same pattern the
        // courses-slug fix (2026_08_09_114244) established, applied from the
        // start here instead of as a follow-up migration.
        DB::statement('create unique index batches_tenant_id_slug_unique on batches (tenant_id, slug) where deleted_at is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
