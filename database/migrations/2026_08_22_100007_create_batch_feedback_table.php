<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'user_id']);
        });

        // 1-5 (design doc §3.2), same CourseReview precedent (S13) — DB
        // backstop, the real out-of-range rejection lives in the Livewire
        // form's own validation.
        DB::statement('ALTER TABLE batch_feedback ADD CONSTRAINT batch_feedback_rating_check CHECK (rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_feedback');
    }
};
