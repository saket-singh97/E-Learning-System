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
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('video_disk_path')->nullable()->after('position');
            $table->unsignedInteger('video_duration_seconds')->nullable()->after('video_disk_path');
            $table->enum('video_status', ['uploading', 'ready', 'failed'])->nullable()->after('video_duration_seconds');
            $table->timestamp('scheduled_at')->nullable()->after('video_status');
            $table->boolean('is_free_preview')->default(false)->after('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn([
                'video_disk_path',
                'video_duration_seconds',
                'video_status',
                'scheduled_at',
                'is_free_preview',
            ]);
        });
    }
};
