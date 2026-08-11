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
        Schema::table('batch_sessions', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->after('batch_id')
                ->constrained('lessons')->nullOnDelete();
            $table->string('live_provider')->nullable()->after('position');
            $table->string('provider_meeting_ref')->nullable()->after('live_provider');
            $table->timestamp('room_created_at')->nullable()->after('provider_meeting_ref');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batch_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_id');
            $table->dropColumn(['live_provider', 'provider_meeting_ref', 'room_created_at']);
        });
    }
};
