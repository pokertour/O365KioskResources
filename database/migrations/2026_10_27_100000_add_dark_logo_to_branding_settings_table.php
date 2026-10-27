<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Second logo for dark backgrounds (dark admin theme, dark room screens).
        // The existing logo_path becomes the logo for light backgrounds.
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->string('logo_dark_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('branding_settings', function (Blueprint $table) {
            $table->dropColumn('logo_dark_path');
        });
    }
};
