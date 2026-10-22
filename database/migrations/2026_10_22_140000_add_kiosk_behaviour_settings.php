<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('soon_minutes')->default(10)->after('show_organizer');
            $table->boolean('allow_end_extend')->default(true)->after('soon_minutes');
            $table->unsignedSmallInteger('text_scale')->default(100)->after('allow_end_extend');
            $table->boolean('night_mode')->default(false)->after('text_scale');
            $table->string('night_start', 5)->default('20:00')->after('night_mode');
            $table->string('night_end', 5)->default('07:00')->after('night_start');
        });

        Schema::table('branding_settings', function (Blueprint $table) {
            $table->string('soon_color', 7)->default('#d97706')->after('busy_color');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['soon_minutes', 'allow_end_extend', 'text_scale', 'night_mode', 'night_start', 'night_end']);
        });

        Schema::table('branding_settings', function (Blueprint $table) {
            $table->dropColumn('soon_color');
        });
    }
};
