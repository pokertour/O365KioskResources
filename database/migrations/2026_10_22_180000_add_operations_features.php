<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // Screen supervision
            $table->timestamp('screen_seen_at')->nullable();
            $table->json('screen_info')->nullable();
            $table->timestamp('offline_alerted_at')->nullable();
            // Equipment shown on the screen
            $table->json('equipment')->nullable();
            // Per-room screen overrides (null = use the global setting)
            $table->string('kiosk_theme', 10)->nullable();
            $table->unsignedSmallInteger('text_scale')->nullable();
            $table->string('day_start', 5)->nullable();
            $table->string('day_end', 5)->nullable();
            $table->boolean('night_mode')->nullable();
            $table->string('night_start', 5)->nullable();
            $table->string('night_end', 5)->nullable();
        });

        Schema::table('app_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('offline_alert_minutes')->default(15);
            $table->boolean('notify_admins_by_email')->default(true);
            $table->text('teams_webhook_url')->nullable();
            $table->boolean('sso_enabled')->default(false);
            $table->boolean('password_login_enabled')->default(true);
            $table->boolean('show_qr_code')->default(true);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('microsoft_oid', 64)->nullable()->unique()->after('email');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor', 10); // screen | admin | system
            $table->string('action', 40);
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['room_id', 'created_at']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('message', 200);
            $table->string('level', 10)->default('info'); // info | warning | danger
            $table->json('room_ids')->nullable(); // null = every screen
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('issue_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('message', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['room_id', 'resolved_at']);
        });

        Schema::create('screen_pairings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->string('device_hash', 64)->unique();
            $table->foreignId('room_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_pairings');
        Schema::dropIfExists('issue_reports');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('activity_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['microsoft_oid']);
            $table->dropColumn('microsoft_oid');
        });

        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['offline_alert_minutes', 'notify_admins_by_email', 'teams_webhook_url', 'sso_enabled', 'password_login_enabled', 'show_qr_code']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'screen_seen_at', 'screen_info', 'offline_alerted_at', 'equipment',
                'kiosk_theme', 'text_scale', 'day_start', 'day_end', 'night_mode', 'night_start', 'night_end',
            ]);
        });
    }
};
