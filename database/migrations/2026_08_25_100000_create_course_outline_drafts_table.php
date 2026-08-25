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
        Schema::create('course_outline_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('topic');
            $table->string('target_level')->nullable();
            $table->enum('status', ['draft', 'approved', 'rejected'])->default('draft');
            $table->jsonb('generated_outline')->nullable();
            $table->foreignId('created_course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->jsonb('token_cost')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_outline_drafts');
    }
};
