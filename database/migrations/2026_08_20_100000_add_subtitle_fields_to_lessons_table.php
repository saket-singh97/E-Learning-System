<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('subtitle_disk_path')->nullable()->after('pdf_disk_path');
            $table->string('subtitle_status')->default('none')->after('subtitle_disk_path');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['subtitle_disk_path', 'subtitle_status']);
        });
    }
};
