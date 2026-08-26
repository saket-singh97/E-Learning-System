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
        Schema::create('monthly_report_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            // First-of-month, e.g. 2026-09-01 for September (design doc §3.2).
            $table->date('period_month');
            $table->enum('status', ['pending', 'generated', 'sent', 'failed'])->default('pending');
            $table->string('pdf_disk_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Versioned by period_month, never overwritten in place (design
            // doc §4 decision 6) — one report card per student per month.
            $table->unique(['student_id', 'period_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_report_cards');
    }
};
