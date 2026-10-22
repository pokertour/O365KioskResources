<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->string('primary_color', 7)->default('#2563eb')->after('logo_path');
            $table->string('available_color', 7)->default('#16a34a')->after('primary_color');
            $table->string('busy_color', 7)->default('#dc2626')->after('available_color');
            $table->string('kiosk_theme', 10)->default('dark')->after('busy_color');
            $table->string('kiosk_footer', 160)->nullable()->after('kiosk_theme');
        });

        // Logos move from the public disk to the private one: they are now served
        // through a controller that neutralises active content (SVG scripts).
        foreach (DB::table('branding_settings')->whereNotNull('logo_path')->get() as $row) {
            if (Storage::disk('public')->exists($row->logo_path)) {
                Storage::disk('local')->put($row->logo_path, Storage::disk('public')->get($row->logo_path));
                Storage::disk('public')->delete($row->logo_path);
            }
        }
    }

    public function down(): void
    {
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->dropColumn(['primary_color', 'available_color', 'busy_color', 'kiosk_theme', 'kiosk_footer']);
        });
    }
};
