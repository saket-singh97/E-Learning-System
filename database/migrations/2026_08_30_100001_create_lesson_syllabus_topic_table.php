<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_syllabus_topic', function (Blueprint $table) {
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_topic_id')->constrained()->cascadeOnDelete();

            $table->primary(['lesson_id', 'syllabus_topic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_syllabus_topic');
    }
};
