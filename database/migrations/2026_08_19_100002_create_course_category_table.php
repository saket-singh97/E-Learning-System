<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plain pivot, no model — matches how every other many-to-many in this
     * app is wired (design doc §3.4). No tenant_id of its own: both sides
     * already carry it, and cascadeOnDelete on either FK is enough to keep
     * the pivot from orphaning.
     */
    public function up(): void
    {
        Schema::create('course_category', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->primary(['course_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_category');
    }
};
