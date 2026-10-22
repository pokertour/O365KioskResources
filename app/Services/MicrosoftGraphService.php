<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\MicrosoftSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class MicrosoftGraphService
{
    private const TOKEN_CACHE_KEY = 'microsoft_graph.access_token';

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private ?array $resolvedCredentials = null;

    public function isConfigured(): bool
    {
        $credentials = $this->credentials();

        return filled($credentials['tenant_id'])
            && filled($credentials['client_id'])
            && filled($credentials['client_secret']);
    }

    /**
     * Tenant, client id and secret, for the admin sign-in flow (same app registration).
     *
     * @return array{tenant_id: ?string, client_id: ?string, client_secret: ?string}
     */
    public function appCredentials(): array
    {
        return $this->credentials();
    }

    public function getAccessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Microsoft Graph is not configured (tenant id, client id, client secret).');
        }

        // The token is stored encrypted: the cache backend (database by default) is not a secret store.
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (\Throwable) {
                Cache::forget(self::TOKEN_CACHE_KEY);
            }
        }

        [$token, $expiresIn] = $this->requestToken();
        Cache::put(self::TOKEN_CACHE_KEY, Crypt::encryptString($token), now()->addSeconds(max(60, $expiresIn - 300)));

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
        $this->resolvedCredentials = null;
    }

    /**
     * Requests a fresh token, bypassing the cache. Used by the admin "test connection" action.
     */
    public function testConnection(): void
    {
        $this->forgetToken();
        $this->getAccessToken();
    }

    /** Application permissions this app may need, and what they are used for. */
    public const APPLICATION_PERMISSIONS = [
        'Calendars.ReadWrite' => true,  // required
        'Place.Read.All' => false,      // optional: room import
    ];

    /**
     * Application permissions actually granted, read from the "roles" claim of the access token.
     * Display only: the token is not verified here, Graph does that on every call.
     *
     * @return list<string>
     */
    public function grantedRoles(): array
    {
        $parts = explode('.', $this->getAccessToken());
        if (count($parts) < 2) {
            return [];
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $roles = is_array($payload) ? ($payload['roles'] ?? []) : [];

        return array_values(array_filter($roles, 'is_string'));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function requestToken(): array
    {
        $credentials = $this->credentials();
        $response = Http::asForm()->timeout(15)->post(
            'https://login.microsoftonline.com/'.rawurlencode((string) $credentials['tenant_id']).'/oauth2/v2.0/token',
            [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        );

        if (! $response->successful()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status();
            throw new RuntimeException('Microsoft token request failed: '.Str::limit(strtok((string) $error, "\r\n"), 300));
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Invalid Microsoft token response.');
        }

        return [$token, (int) ($response->json('expires_in') ?? 3600)];
    }

    /**
     * @return list<array{id: string, subject: string, start: string, end: string, organizer: ?string, organizer_email: ?string, is_private: bool, is_all_day: bool}>
     */
    public function listCalendarView(string $resourceEmail, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tz = AppSetting::current()->timezone();
        $encoded = rawurlencode($resourceEmail);
        $url = self::GRAPH_BASE."/users/{$encoded}/calendar/calendarView";

        $response = Http::withToken($this->getAccessToken())
            ->timeout(15)
            ->withHeaders([
                'Prefer' => 'outlook.timezone="'.$tz.'"',
            ])
            ->get($url, [
                'startDateTime' => $start->format(\DateTimeInterface::ATOM),
                'endDateTime' => $end->format(\DateTimeInterface::ATOM),
                '$select' => 'id,subject,start,end,organizer,sensitivity,isAllDay,isCancelled,showAs,isOnlineMeeting,onlineMeetingProvider',
                '$orderby' => 'start/dateTime',
                '$top' => 100,
            ]);

        $this->throwUnlessSuccessful($response, $resourceEmail);

        $events = [];
        foreach ($response->json('value', []) as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                continue;
            }
            if (($row['isCancelled'] ?? false) === true || ($row['showAs'] ?? null) === 'free') {
                continue;
            }
            $startDt = $row['start']['dateTime'] ?? '';
            $endDt = $row['end']['dateTime'] ?? '';
            $organizer = $row['organizer']['emailAddress']['name'] ?? $row['organizer']['emailAddress']['address'] ?? null;
            $organizerEmail = $row['organizer']['emailAddress']['address'] ?? null;
            $events[] = [
                'id' => $row['id'],
                'subject' => is_string($row['subject'] ?? null) ? $row['subject'] : '',
                'start' => is_string($startDt) ? $startDt : '',
                'end' => is_string($endDt) ? $endDt : '',
                'organizer' => is_string($organizer) ? $organizer : null,
                'organizer_email' => is_string($organizerEmail) ? strtolower($organizerEmail) : null,
                'is_private' => in_array($row['sensitivity'] ?? 'normal', ['private', 'confidential'], true),
                'is_all_day' => ($row['isAllDay'] ?? false) === true,
                'online' => ($row['isOnlineMeeting'] ?? false) === true
                    ? (($row['onlineMeetingProvider'] ?? null) === 'teamsForBusiness' ? 'teams' : 'online')
                    : null,
            ];
        }

        return $events;
    }

    /**
     * Room mailboxes declared in the tenant (requires the Place.Read.All application permission).
     *
     * @return list<array{name: string, email: string, capacity: ?int, location: ?string}>
     */
    public function listRooms(): array
    {
        $rooms = [];
        $url = self::GRAPH_BASE.'/places/microsoft.graph.room?$top=100';

        $retried = false;
        for ($page = 0; $url && $page < 20; $page++) {
            $response = Http::withToken($this->getAccessToken())->timeout(20)->get($url);

            // Permissions are frozen in the token when it is issued: after an admin grants
            // Place.Read.All, a cached token still lacks it. Retry once with a fresh one.
            if ($response->status() === 403 && ! $retried) {
                $retried = true;
                $this->forgetToken();
                $page--;

                continue;
            }

            $this->throwUnlessSuccessful($response, 'places');

            foreach ($response->json('value', []) as $row) {
                $email = $row['emailAddress'] ?? null;
                if (! is_string($email) || $email === '') {
                    continue;
                }
                $location = collect([$row['building'] ?? null, $row['floorLabel'] ?? null])->filter(fn ($v) => is_string($v) && $v !== '')->implode(' · ');
                $rooms[] = [
                    'name' => is_string($row['displayName'] ?? null) ? $row['displayName'] : $email,
                    'email' => strtolower($email),
                    'capacity' => is_int($row['capacity'] ?? null) && $row['capacity'] > 0 ? $row['capacity'] : null,
                    'location' => $location !== '' ? Str::limit($location, 120, '') : null,
                ];
            }

            $next = $response->json('@odata.nextLink');
            $url = is_string($next) && str_starts_with($next, self::GRAPH_BASE) ? $next : null;
        }

        usort($rooms, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rooms;
    }

    /**
     * Events over a long period (statistics), following Graph's paging.
     *
     * @return list<array<string, mixed>>
     */
    public function listCalendarRange(string $resourceEmail, \DateTimeInterface $start, \DateTimeInterface $end, int $maxEvents = 2000): array
    {
        $tz = AppSetting::current()->timezone();
        $url = self::GRAPH_BASE.'/users/'.rawurlencode($resourceEmail).'/calendar/calendarView?'.http_build_query([
            'startDateTime' => $start->format(\DateTimeInterface::ATOM),
            'endDateTime' => $end->format(\DateTimeInterface::ATOM),
            '$select' => 'id,start,end,isAllDay,isCancelled,showAs,organizer',
            '$top' => 500,
        ]);

        $events = [];
        while ($url && count($events) < $maxEvents) {
            $response = Http::withToken($this->getAccessToken())
                ->timeout(30)
                ->withHeaders(['Prefer' => 'outlook.timezone="'.$tz.'"'])
                ->get($url);
            $this->throwUnlessSuccessful($response, $resourceEmail);

            foreach ($response->json('value', []) as $row) {
                if (! is_array($row) || ($row['isCancelled'] ?? false) || ($row['showAs'] ?? null) === 'free' || ($row['isAllDay'] ?? false)) {
                    continue;
                }
                $events[] = [
                    'start' => $row['start']['dateTime'] ?? null,
                    'end' => $row['end']['dateTime'] ?? null,
                    'organizer_email' => strtolower((string) ($row['organizer']['emailAddress']['address'] ?? '')),
                ];
            }

            $next = $response->json('@odata.nextLink');
            $url = is_string($next) && str_starts_with($next, self::GRAPH_BASE) ? $next : null;
        }

        return $events;
    }

    public function createCalendarEvent(
        string $resourceEmail,
        string $subject,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): string {
        $tz = AppSetting::current()->timezone();
        $encoded = rawurlencode($resourceEmail);
        $url = self::GRAPH_BASE."/users/{$encoded}/events";

        $body = [
            'subject' => $subject,
            'start' => [
                'dateTime' => $start->format('Y-m-d\TH:i:s'),
                'timeZone' => $tz,
            ],
            'end' => [
                'dateTime' => $end->format('Y-m-d\TH:i:s'),
                'timeZone' => $tz,
            ],
            'body' => [
                'contentType' => 'text',
                'content' => '',
            ],
        ];

        $response = Http::withToken($this->getAccessToken())
            ->timeout(15)
            ->post($url, $body);

        $this->throwUnlessSuccessful($response, $resourceEmail);

        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Microsoft Graph did not return an event id.');
        }

        return $id;
    }

    /**
     * Moves the end of an event (end a meeting early, or extend it).
     */
    public function updateEventEnd(string $resourceEmail, string $eventId, \DateTimeInterface $end): void
    {
        $this->updateCalendarEvent($resourceEmail, $eventId, end: $end);
    }

    /**
     * Changes the subject and/or times of an event. Omitted fields are left untouched.
     */
    public function updateCalendarEvent(
        string $resourceEmail,
        string $eventId,
        ?string $subject = null,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
    ): void {
        $tz = AppSetting::current()->timezone();
        $body = array_filter([
            'subject' => $subject,
            'start' => $start ? ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $tz] : null,
            'end' => $end ? ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => $tz] : null,
        ], fn ($v) => $v !== null);

        $url = self::GRAPH_BASE.'/users/'.rawurlencode($resourceEmail).'/events/'.rawurlencode($eventId);
        $response = Http::withToken($this->getAccessToken())->timeout(15)->patch($url, $body);

        $this->throwUnlessSuccessful($response, $resourceEmail);
    }

    public function deleteCalendarEvent(string $resourceEmail, string $eventId): void
    {
        $encodedUser = rawurlencode($resourceEmail);
        $encodedEvent = rawurlencode($eventId);
        $url = self::GRAPH_BASE."/users/{$encodedUser}/events/{$encodedEvent}";

        $response = Http::withToken($this->getAccessToken())->timeout(15)->delete($url);

        if ($response->status() === 404) {
            return;
        }

        $this->throwUnlessSuccessful($response, $resourceEmail);
    }

    private function throwUnlessSuccessful(Response $response, string $resourceEmail): void
    {
        if ($response->successful()) {
            return;
        }

        // Keep only Graph's error code/message: the raw body may be large and is not meant for display.
        $code = $response->json('error.code');
        $message = $response->json('error.message');
        $detail = is_string($code) ? $code.(is_string($message) ? ' – '.Str::limit($message, 200) : '') : 'HTTP '.$response->status();

        throw new RuntimeException('Microsoft Graph ('.$response->status().') pour '.$resourceEmail.' : '.$detail);
    }

    /**
     * @return array{tenant_id: ?string, client_id: ?string, client_secret: ?string}
     */
    private function credentials(): array
    {
        if ($this->resolvedCredentials !== null) {
            return $this->resolvedCredentials;
        }

        if (Schema::hasTable('microsoft_settings')) {
            $settings = MicrosoftSetting::current();
            $tenant = trim((string) $settings->tenant_id);
            $client = trim((string) $settings->client_id);
            $secret = trim((string) $settings->client_secret);

            // Keep env fallback for transition if DB settings are empty.
            $this->resolvedCredentials = [
                'tenant_id' => $tenant !== '' ? $tenant : config('services.microsoft.tenant_id'),
                'client_id' => $client !== '' ? $client : config('services.microsoft.client_id'),
                'client_secret' => $secret !== '' ? $secret : config('services.microsoft.client_secret'),
            ];

            return $this->resolvedCredentials;
        }

        $this->resolvedCredentials = [
            'tenant_id' => config('services.microsoft.tenant_id'),
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
        ];

        return $this->resolvedCredentials;
    }
}
