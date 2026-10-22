<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Screen;
use App\Services\AdminNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('screens:check-offline')]
#[Description('Alert administrators about room screens that stopped reporting')]
class CheckOfflineScreens extends Command
{
    public function handle(AdminNotifier $notifier): int
    {
        $minutes = AppSetting::current()->offline_alert_minutes;
        if ($minutes <= 0) {
            return self::SUCCESS;
        }

        // Only screens that have reported at least once, and not alerted yet for this outage.
        $screens = Screen::query()
            ->with('room')
            ->whereNotNull('seen_at')
            ->where('seen_at', '<', now()->subMinutes($minutes))
            ->whereNull('offline_alerted_at')
            ->get();

        foreach ($screens as $screen) {
            Screen::query()->toBase()->where('id', $screen->id)->update(['offline_alerted_at' => now()]);
            ActivityLog::record('screen.offline', $screen->room, $screen->name, actor: 'system');
            $notifier->send(
                __('Screen offline: :room', ['room' => $screen->fullName()]),
                __('The screen of :room has not reported since :time.', ['room' => $screen->fullName(), 'time' => $screen->seen_at->setTimezone(AppSetting::current()->timezone())->format('d/m H:i')]),
                route('admin.rooms.show', ['room' => $screen->room, 'tab' => 'screen']),
                __('Open the room'),
            );
            $this->line("Alerted: {$screen->fullName()}");
        }

        return self::SUCCESS;
    }
}
