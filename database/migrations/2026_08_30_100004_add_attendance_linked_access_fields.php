<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('attendance_linked_access_enabled')->default(false);
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->smallInteger('min_attendance_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('attendance_linked_access_enabled');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('min_attendance_percent');
        });
    }
};
