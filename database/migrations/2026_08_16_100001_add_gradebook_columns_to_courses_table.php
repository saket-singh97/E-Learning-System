<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->jsonb('gradebook_weights')->default('{"quiz":50,"assignment":50}')->after('status');
            $table->decimal('passing_threshold', 5, 2)->default(40.00)->after('gradebook_weights');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['gradebook_weights', 'passing_threshold']);
        });
    }
};
