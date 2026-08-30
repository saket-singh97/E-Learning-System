<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->boolean('is_pyq')->default(false);
            $table->string('pyq_exam_name')->nullable();
            $table->smallInteger('pyq_exam_year')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['is_pyq', 'pyq_exam_name', 'pyq_exam_year']);
        });
    }
};
