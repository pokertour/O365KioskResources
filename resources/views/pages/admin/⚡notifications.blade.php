<?php

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Services\AdminNotifier;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    public int $offline_alert_minutes = 15;

    public bool $notify_admins_by_email = true;

    public string $teams_webhook_url = '';

    public bool $hasWebhook = false;

    public bool $removeWebhook = false;

    public function mount(): void
    {
        $s = AppSetting::current();
        $this->offline_alert_minutes = $s->offline_alert_minutes;
        $this->notify_admins_by_email = $s->notify_admins_by_email;
        $this->hasWebhook = filled($s->teams_webhook_url);
    }

    public function save(): void
    {
        $this->validate([
            'offline_alert_minutes' => ['required', 'integer', Rule::in(AppSetting::OFFLINE_ALERT_OPTIONS)],
            'notify_admins_by_email' => ['boolean'],
            // Teams "Workflows" webhooks live on Microsoft domains; HTTPS only.
            'teams_webhook_url' => ['nullable', 'url:https', 'max:2048', 'regex:#^https://[^/]+\.(logic\.azure\.com|powerplatform\.com|office\.com|microsoft\.com|azure\.com)(:\d+)?/#i'],
        ], [
            'teams_webhook_url.regex' => __('Paste the URL of a Teams “Workflows” webhook (it starts with https:// and ends on a Microsoft domain).'),
        ], [
            'teams_webhook_url' => __('webhook URL'),
        ]);

        $s = AppSetting::current();
        $s->offline_alert_minutes = $this->offline_alert_minutes;
        $s->notify_admins_by_email = $this->notify_admins_by_email;
        if ($this->removeWebhook) {
            $s->teams_webhook_url = null;
        } elseif (trim($this->teams_webhook_url) !== '') {
            $s->teams_webhook_url = trim($this->teams_webhook_url);
        }
        $s->save();
        ActivityLog::record('settings.updated', null, __('Notifications'));

        $this->hasWebhook = filled($s->teams_webhook_url);
        $this->teams_webhook_url = '';
        $this->removeWebhook = false;
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function sendTest(): void
    {
        app(AdminNotifier::class)->send(
            __('Test notification'),
            __('If you read this, notifications from :app work.', ['app' => \App\Models\BrandingSetting::current()->displayName()]),
            route('admin.rooms.index'),
            __('Open the administration'),
        );

        Flux::toast(variant: 'success', text: __('Test notification sent.'));
    }
}; ?>

<div>
    <x-admin.page-header :title="__('Notifications')" :description="__('Who is warned, and how, when a screen goes offline or someone reports a problem.')" />

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <x-admin.card :title="__('Screen supervision')">
            <flux:radio.group wire:model="offline_alert_minutes" :label="__('Alert when a screen stops reporting for')" variant="segmented" class="sm:max-w-lg">
                @foreach (\App\Models\AppSetting::OFFLINE_ALERT_OPTIONS as $minutes)
                    <flux:radio :value="$minutes" :label="$minutes === 0 ? __('Never') : fmt_duration($minutes)" />
                @endforeach
            </flux:radio.group>
            <p class="mt-3 text-xs text-zinc-500">{!! __('Requires the Laravel scheduler on the server: :cmd', ['cmd' => '<code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-white/10">* * * * * php artisan schedule:run</code>']) !!}</p>
        </x-admin.card>

        <x-admin.card :title="__('Channels')">
            <div class="space-y-6">
                <flux:switch wire:model="notify_admins_by_email" :label="__('E-mail the administrators')" :description="__('Every administrator with a verified e-mail address receives the alerts.')" />

                <div>
                    <flux:label>{{ __('Microsoft Teams channel') }}</flux:label>
                    <flux:description class="mt-1">{{ __('In Teams, add the “Post to a channel when a webhook request is received” workflow to a channel, then paste its URL here.') }}</flux:description>
                    @if ($hasWebhook && ! $removeWebhook)
                        <div class="mt-3 flex items-center gap-3">
                            <flux:badge color="green" icon="check-circle" size="sm">{{ __('Configured') }}</flux:badge>
                            <flux:button size="sm" variant="ghost" wire:click="$set('removeWebhook', true)">{{ __('Remove') }}</flux:button>
                        </div>
                    @endif
                    <flux:input wire:model="teams_webhook_url" type="url" class="mt-3" :placeholder="$hasWebhook ? __('Paste a new URL to replace it') : 'https://…logic.azure.com/…'" autocomplete="off" />
                    @if ($removeWebhook)
                        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">{{ __('The webhook will be removed when you save.') }}</p>
                    @endif
                </div>
            </div>
        </x-admin.card>

        <div class="flex flex-wrap justify-end gap-2">
            <flux:button type="button" variant="ghost" icon="paper-airplane" wire:click="sendTest">{{ __('Send a test') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>
</div>
