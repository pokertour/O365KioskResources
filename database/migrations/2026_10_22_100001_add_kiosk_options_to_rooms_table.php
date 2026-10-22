<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->text('kiosk_token')->nullable()->after('kiosk_token_hash');
            $table->string('location', 120)->nullable()->after('o365_resource_email');
            $table->unsignedSmallInteger('capacity')->nullable()->after('location');
            $table->boolean('booking_enabled')->default(true)->after('kiosk_token');
            $table->boolean('show_subjects')->default(true)->after('booking_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['kiosk_token', 'location', 'capacity', 'booking_enabled', 'show_subjects']);
        });
    }
};
