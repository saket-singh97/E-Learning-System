<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named lesson_pdf_notes, not lesson_notes — that table already exists
     * (S13, student-authored video-timestamp notes). One row per lesson;
     * regenerating overwrites disk_path (design doc §3.2) since there's no
     * multi-version consumer contract the way LessonArtifact has.
     */
    public function up(): void
    {
        Schema::create('lesson_pdf_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('disk_path')->nullable();
            $table->enum('status', ['pending', 'ready', 'failed'])->default('pending');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_pdf_notes');
    }
};
