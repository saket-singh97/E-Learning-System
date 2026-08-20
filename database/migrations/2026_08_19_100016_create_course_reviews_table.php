<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->text('instructor_response')->nullable();
            $table->timestamp('instructor_response_at')->nullable();
            // Moderation flag, default visible — hide-not-delete
            // (design doc decision 11, built out in B15).
            $table->string('status')->default('published');
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);
        });

        // 1-5 (design doc §3.1) — DB-enforced as a backstop, same
        // belt-and-suspenders posture assignment_submissions.version_number
        // already takes (S10 B3); the real "reject an out-of-range rating"
        // UX lives in the Livewire form's own validation.
        DB::statement('ALTER TABLE course_reviews ADD CONSTRAINT course_reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('course_reviews');
    }
};
