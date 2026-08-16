<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->jsonb('files');
            $table->timestamp('submitted_at');
            $table->boolean('is_late')->default(false);
            $table->decimal('marks_awarded', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->jsonb('rubric_scores')->nullable();
            $table->foreignId('graded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'user_id', 'version_number']);
        });

        // version_number 1-3 (design doc §2.4 decision 3) — DB-enforced as a
        // backstop, the real "resubmission limit reached" UX lives in the
        // service layer (B3), same belt-and-suspenders posture the
        // certificates.tenant_id/user_id/course_id unique index takes below.
        DB::statement('ALTER TABLE assignment_submissions ADD CONSTRAINT assignment_submissions_version_number_check CHECK (version_number BETWEEN 1 AND 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
    }
};
