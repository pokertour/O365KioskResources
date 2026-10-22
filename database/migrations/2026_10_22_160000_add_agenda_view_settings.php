<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('agenda_view', 10)->default('timeline')->after('night_end');
            $table->string('day_start', 5)->default('08:00')->after('agenda_view');
            $table->string('day_end', 5)->default('19:00')->after('day_start');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['agenda_view', 'day_start', 'day_end']);
        });
    }
};
