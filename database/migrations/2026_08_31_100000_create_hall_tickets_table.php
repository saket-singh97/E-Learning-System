<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_test_registration_id')->constrained()->cascadeOnDelete();
            $table->string('roll_number');
            $table->string('pdf_path');
            $table->timestamps();

            $table->unique('scholarship_test_registration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_tickets');
    }
};
