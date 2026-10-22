<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('token_hash', 64)->index();
            $table->text('token')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->json('info')->nullable();
            $table->timestamp('offline_alerted_at')->nullable();
            // Per-screen overrides (null = room setting, then global setting)
            $table->string('kiosk_theme', 10)->nullable();
            $table->unsignedSmallInteger('text_scale')->nullable();
            $table->boolean('night_mode')->nullable();
            $table->string('night_start', 5)->nullable();
            $table->string('night_end', 5)->nullable();
            $table->timestamps();
        });

        Schema::table('screen_pairings', function (Blueprint $table) {
            $table->foreignId('screen_id')->nullable()->after('room_id')->constrained()->cascadeOnDelete();
        });

        // Each room's current link and supervision data become its first screen: screens already
        // installed keep working with the same URL.
        $locale = DB::table('app_settings')->value('locale') ?? 'fr';
        foreach (DB::table('rooms')->get() as $room) {
            DB::table('screens')->insert([
                'room_id' => $room->id,
                'name' => $locale === 'fr' ? 'Écran principal' : 'Main screen',
                'token_hash' => $room->kiosk_token_hash,
                'token' => $room->kiosk_token,
                'seen_at' => $room->screen_seen_at,
                'info' => $room->screen_info,
                'offline_alerted_at' => $room->offline_alerted_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['kiosk_token_hash', 'kiosk_token', 'screen_seen_at', 'screen_info', 'offline_alerted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('kiosk_token_hash', 64)->default('');
            $table->text('kiosk_token')->nullable();
            $table->timestamp('screen_seen_at')->nullable();
            $table->json('screen_info')->nullable();
            $table->timestamp('offline_alerted_at')->nullable();
        });

        foreach (DB::table('screens')->orderBy('id')->get()->unique('room_id') as $screen) {
            DB::table('rooms')->where('id', $screen->room_id)->update([
                'kiosk_token_hash' => $screen->token_hash,
                'kiosk_token' => $screen->token,
                'screen_seen_at' => $screen->seen_at,
                'screen_info' => $screen->info,
                'offline_alerted_at' => $screen->offline_alerted_at,
            ]);
        }

        Schema::table('screen_pairings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('screen_id');
        });
        Schema::dropIfExists('screens');
    }
};
