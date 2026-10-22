<?php

namespace App\Jobs;

use App\Services\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers an administrator alert outside of the request that triggered it (screen heartbeat,
 * issue report…), so a slow mail server or Teams webhook never slows a room screen down.
 */
class SendAdminAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $actionLabel = null,
    ) {}

    public function handle(AdminNotifier $notifier): void
    {
        $notifier->deliver($this->title, $this->body, $this->url, $this->actionLabel);
    }
}
