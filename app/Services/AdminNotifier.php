<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use App\Notifications\AdminAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends operational alerts (screen offline, issue reported…) to the administrators,
 * by e-mail and/or to a Microsoft Teams channel, as configured in the Notifications settings.
 * Failures are logged, never thrown: an alert must not break the screen that triggered it.
 */
class AdminNotifier
{
    public function send(string $title, string $body, ?string $url = null, ?string $actionLabel = null): void
    {
        $settings = AppSetting::current();

        if ($settings->notify_admins_by_email) {
            try {
                $admins = User::query()->where('is_admin', true)->whereNotNull('email_verified_at')->get();
                Notification::send($admins, new AdminAlert($title, $body, $url, $actionLabel));
            } catch (\Throwable $e) {
                Log::warning('Admin e-mail alert failed', ['exception' => $e->getMessage()]);
            }
        }

        if (filled($settings->teams_webhook_url)) {
            $this->sendToTeams($settings->teams_webhook_url, $title, $body, $url, $actionLabel);
        }
    }

    /**
     * Posts an Adaptive Card, the format expected by Teams "Workflows" incoming webhooks.
     */
    public function sendToTeams(string $webhookUrl, string $title, string $body, ?string $url = null, ?string $actionLabel = null): bool
    {
        $card = [
            'type' => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'body' => [
                ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                ['type' => 'TextBlock', 'text' => $body, 'wrap' => true],
            ],
            'actions' => $url ? [['type' => 'Action.OpenUrl', 'title' => $actionLabel ?? __('Open'), 'url' => $url]] : [],
        ];

        try {
            $response = Http::timeout(10)->post($webhookUrl, [
                'type' => 'message',
                'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]],
            ]);

            if (! $response->successful()) {
                Log::warning('Teams alert rejected', ['status' => $response->status()]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Teams alert failed', ['exception' => $e->getMessage()]);

            return false;
        }
    }
}
