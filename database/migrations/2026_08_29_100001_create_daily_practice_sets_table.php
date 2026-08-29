<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_practice_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->date('practice_date');
            $table->jsonb('question_ids');
            $table->timestamps();
            $table->unique(['batch_id', 'practice_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_practice_sets');
    }
};
