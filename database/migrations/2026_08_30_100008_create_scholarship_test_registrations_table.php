<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_test_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_test_id')->constrained()->cascadeOnDelete();
            // Prospect, no user_id — same shape as CourseInterestSignup
            // (design doc §3.2): they aren't a User yet.
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->timestamps();
            $table->unique(['scholarship_test_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_test_registrations');
    }
};
