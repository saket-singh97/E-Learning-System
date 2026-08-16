<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('certificate_signature_path')->nullable()->after('favicon_path');
            $table->string('certificate_background_path')->nullable()->after('certificate_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['certificate_signature_path', 'certificate_background_path']);
        });
    }
};
