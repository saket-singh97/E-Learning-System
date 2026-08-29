<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per lesson, "latest wins" — same shape as lesson_pdf_notes
     * (design doc §3.2): a re-extraction overwrites the row, no
     * multi-version consumer contract the way LessonArtifact needs one.
     */
    public function up(): void
    {
        Schema::create('lesson_audio_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('disk_path')->nullable();
            $table->enum('status', ['pending', 'ready', 'failed'])->default('pending');
            $table->timestamps();
            $table->unique('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_audio_tracks');
    }
};
