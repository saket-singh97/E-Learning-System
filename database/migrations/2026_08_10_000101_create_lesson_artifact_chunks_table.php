<?php

use App\Integrations\Drivers\FakeAiDriver;
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
        Schema::create('lesson_artifact_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_artifact_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_idx');
            $table->float('start_s');
            $table->float('end_s');
            $table->text('text');
            $table->vector('embedding', FakeAiDriver::DIMENSIONS)->nullable();
        });

        // Fluent Blueprint has no opclass support; pgvector's own docs recommend
        // raw DDL for the approximate index. Cosine ops to match doubt chat's
        // planned retrieval (HasNeighbors::scopeNearestNeighbors, Distance::Cosine).
        DB::statement(
            'CREATE INDEX lesson_artifact_chunks_embedding_hnsw ON lesson_artifact_chunks '.
            'USING hnsw (embedding vector_cosine_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_artifact_chunks');
    }
};
