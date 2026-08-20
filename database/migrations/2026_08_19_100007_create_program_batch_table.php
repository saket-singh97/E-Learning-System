<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Names the exact sellable batches a program grants access to (design
     * doc decision 3) — Batch, not Course, is this app's real sellable/
     * enrollable unit. Plain pivot, no model, matches every other
     * many-to-many in this app.
     */
    public function up(): void
    {
        Schema::create('program_batch', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();

            $table->primary(['program_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_batch');
    }
};
