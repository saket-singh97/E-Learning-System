<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S19 N16 pt.3 — dedup marker for `HelpDeskSlaSweep`: without it, a
 * breached ticket left open would get re-notified every single day
 * forever (`sla_due_at` stays in the past permanently once passed), same
 * "flag once, never re-flag" reasoning `TeacherAttendanceFlag`'s own
 * per-session dedup uses (S16), just a column here rather than a whole
 * second table since there's exactly one boolean fact to track.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_desk_tickets', function (Blueprint $table) {
            $table->timestamp('sla_breach_notified_at')->nullable()->after('sla_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('help_desk_tickets', function (Blueprint $table) {
            $table->dropColumn('sla_breach_notified_at');
        });
    }
};
