<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 5)->default('fr');
            $table->string('timezone', 64)->default('Europe/Paris');
            $table->string('time_format', 3)->default('24h');
            $table->unsignedSmallInteger('kiosk_refresh_seconds')->default(30);
            $table->json('quick_book_durations')->nullable();
            $table->unsignedSmallInteger('max_booking_minutes')->default(240);
            $table->boolean('show_organizer')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
