<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MicrosoftGraphService
{
    private const TOKEN_CACHE_KEY = 'microsoft_graph.access_token';

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    public function isConfigured(): bool
    {
        return filled(config('services.microsoft.tenant_id'))
            && filled(config('services.microsoft.client_id'))
            && filled(config('services.microsoft.client_secret'));
    }

    public function getAccessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Microsoft Graph is not configured (AZURE_TENANT_ID, AZURE_CLIENT_ID, AZURE_CLIENT_SECRET).');
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(50), function (): string {
            $tenant = config('services.microsoft.tenant_id');
            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
                [
                    'client_id' => config('services.microsoft.client_id'),
                    'client_secret' => config('services.microsoft.client_secret'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            );

            if (! $response->successful()) {
                throw new RuntimeException('Microsoft token request failed: '.$response->body());
            }

            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Invalid Microsoft token response.');
            }

            return $token;
        });
    }

    /**
     * @return list<array{id: string, subject: string, start: string, end: string, organizer?: string|null}>
     */
    public function listCalendarView(string $resourceEmail, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tz = config('app.timezone', 'UTC');
        $encoded = rawurlencode($resourceEmail);
        $url = self::GRAPH_BASE."/users/{$encoded}/calendar/calendarView";

        $response = Http::withToken($this->getAccessToken())
            ->withHeaders([
                'Prefer' => 'outlook.timezone="'.$tz.'"',
            ])
            ->get($url, [
                'startDateTime' => $start->format(\DateTimeInterface::ATOM),
                'endDateTime' => $end->format(\DateTimeInterface::ATOM),
            ]);

        $this->throwUnlessSuccessful($response, $resourceEmail);

        $events = [];
        foreach ($response->json('value', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }
            $subject = is_string($row['subject'] ?? null) ? $row['subject'] : '';
            $startDt = $row['start']['dateTime'] ?? '';
            $endDt = $row['end']['dateTime'] ?? '';
            $organizer = $row['organizer']['emailAddress']['address'] ?? null;
            $events[] = [
                'id' => $id,
                'subject' => $subject,
                'start' => is_string($startDt) ? $startDt : '',
                'end' => is_string($endDt) ? $endDt : '',
                'organizer' => is_string($organizer) ? $organizer : null,
            ];
        }

        return $events;
    }

    public function createCalendarEvent(
        string $resourceEmail,
        string $subject,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): string {
        $tz = config('app.timezone', 'UTC');
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
            ->post($url, $body);

        $this->throwUnlessSuccessful($response, $resourceEmail);

        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Microsoft Graph did not return an event id.');
        }

        return $id;
    }

    public function deleteCalendarEvent(string $resourceEmail, string $eventId): void
    {
        $encodedUser = rawurlencode($resourceEmail);
        $encodedEvent = rawurlencode($eventId);
        $url = self::GRAPH_BASE."/users/{$encodedUser}/events/{$encodedEvent}";

        $response = Http::withToken($this->getAccessToken())->delete($url);

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

        throw new RuntimeException('Microsoft Graph error for '.$resourceEmail.': '.$response->body());
    }
}
