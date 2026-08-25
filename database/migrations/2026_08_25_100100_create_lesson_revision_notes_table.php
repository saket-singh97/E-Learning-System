<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lesson_revision_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_artifact_version');
            $table->enum('status', ['pending', 'ready', 'failed'])->default('pending');
            $table->jsonb('notes')->nullable();
            $table->jsonb('token_cost')->nullable();
            $table->timestamps();
            // Regenerating notes for a newer artifact version is a new row,
            // not an overwrite — mirrors LessonArtifact's own
            // versioned-not-overwritten shape (S17 design §4 decision 9).
            $table->unique(['lesson_id', 'source_artifact_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_revision_notes');
    }
};
