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
        Schema::table('quizzes', function (Blueprint $table) {
            // Nullable — a quiz not in a series keeps this null, same
            // "additive, zero blast radius" shape fee_invoices.program_id
            // (S13) already established. Deleting a series unlinks its
            // quizzes rather than deleting them.
            $table->foreignId('test_series_id')->nullable()->after('proctoring_enabled')->constrained('test_series')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('test_series_id');
        });
    }
};
