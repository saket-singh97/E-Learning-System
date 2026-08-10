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
        Schema::create('lesson_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('source_asset_version');
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $table->text('transcript')->nullable();
            $table->jsonb('chunks')->nullable();
            $table->jsonb('concepts')->nullable();
            $table->jsonb('syllabus_map')->nullable();
            $table->jsonb('difficulty')->nullable();
            $table->jsonb('seed_questions')->nullable();
            $table->jsonb('model_route')->nullable();
            $table->jsonb('token_cost')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['lesson_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_artifacts');
    }
};
