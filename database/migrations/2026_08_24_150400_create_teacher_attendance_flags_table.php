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
        Schema::create('teacher_attendance_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_session_id')->constrained()->cascadeOnDelete();
            // The batch's own instructor_id at flag-creation time — a
            // stamp, not a live reference: an admin's later reassignment
            // (the resolve action) must not silently rewrite who this
            // flag was originally raised against.
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('flagged_at');
            $table->string('status')->default('open');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_attendance_flags');
    }
};
