<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_interest_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // One signup per prospect per course (design doc §3.2) — a
            // course_id is only ever valid within the one tenant that owns
            // it, so this pair is already tenant-unique without adding
            // tenant_id into the index.
            $table->unique(['course_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_interest_signups');
    }
};
