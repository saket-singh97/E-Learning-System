<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolment_id')->constrained()->cascadeOnDelete();
            $table->string('card_number');
            $table->string('disk_path');
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'card_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_cards');
    }
};
