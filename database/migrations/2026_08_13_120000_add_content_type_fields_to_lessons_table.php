<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Confirmed by reading every lessons migration, not assumed from the
     * plan doc's own wording: no column for any of these three existed
     * anywhere — S2 only ever built the video path. `pdf_disk_path` is a
     * separate column from `video_disk_path`, not a rename/reuse of it —
     * same 'local' disk, same signed-URL pattern, but a lesson row that
     * happened to change type would otherwise leave a stale, wrongly-typed
     * value sitting in a column named for the other content type.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->text('text_body')->nullable()->after('is_free_preview');
            $table->string('embed_url')->nullable()->after('text_body');
            $table->string('pdf_disk_path')->nullable()->after('embed_url');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['text_body', 'embed_url', 'pdf_disk_path']);
        });
    }
};
