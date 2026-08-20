<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reported message doesn't auto-hide — "flag, don't auto-act" (design
     * doc decision 10, same posture AccountSharingFlag/S12 B4 already
     * takes). status defaults 'open'; an admin actions or dismisses it.
     */
    public function up(): void
    {
        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('direct_message_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('status')->default('open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reports');
    }
};
