<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\MicrosoftGraphService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Administrator sign-in with Microsoft Entra ID (OAuth 2.0 authorization code + PKCE),
 * using the app registration already configured for Microsoft Graph.
 * Only existing administrator accounts can sign in: matched by Entra object id, then by e-mail.
 */
class MicrosoftLoginController extends Controller
{
    public function __construct(private MicrosoftGraphService $graph) {}

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->available(), 404);

        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('microsoft_oauth', ['state' => $state, 'verifier' => $verifier]);

        $credentials = $this->graph->appCredentials();
        $query = http_build_query([
            'client_id' => $credentials['client_id'],
            'response_type' => 'code',
            'redirect_uri' => route('auth.microsoft.callback'),
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);

        return redirect()->away('https://login.microsoftonline.com/'.rawurlencode((string) $credentials['tenant_id']).'/oauth2/v2.0/authorize?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->available(), 404);

        $flow = $request->session()->pull('microsoft_oauth');
        if (! is_array($flow) || ! is_string($request->query('state')) || ! hash_equals($flow['state'], $request->query('state'))) {
            return $this->fail(__('The sign-in request expired. Please try again.'));
        }
        if ($request->filled('error')) {
            return $this->fail(__('Microsoft sign-in was cancelled or refused.'));
        }

        $credentials = $this->graph->appCredentials();
        $token = Http::asForm()->timeout(15)->post(
            'https://login.microsoftonline.com/'.rawurlencode((string) $credentials['tenant_id']).'/oauth2/v2.0/token',
            [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'grant_type' => 'authorization_code',
                'code' => (string) $request->query('code'),
                'redirect_uri' => route('auth.microsoft.callback'),
                'code_verifier' => $flow['verifier'],
                'scope' => 'openid profile email User.Read',
            ],
        );

        if (! $token->successful() || ! is_string($token->json('access_token'))) {
            Log::warning('Microsoft sign-in token exchange failed', ['status' => $token->status(), 'error' => $token->json('error')]);

            return $this->fail(__('Microsoft sign-in failed. Check the redirect URI of the app registration.'));
        }

        $me = Http::withToken($token->json('access_token'))->timeout(15)
            ->get('https://graph.microsoft.com/v1.0/me', ['$select' => 'id,mail,userPrincipalName,displayName']);
        $oid = $me->json('id');
        $email = strtolower((string) ($me->json('mail') ?: $me->json('userPrincipalName')));

        if (! $me->successful() || ! is_string($oid) || $email === '') {
            return $this->fail(__('Microsoft sign-in failed. Please try again.'));
        }

        $user = User::query()->where('microsoft_oid', $oid)->first()
            ?? User::query()->whereRaw('lower(email) = ?', [$email])->whereNull('microsoft_oid')->first();

        if (! $user) {
            return $this->fail(__('No account matches :email. Ask an administrator to add you.', ['email' => $email]));
        }

        // Microsoft sign-in is for administrators only (a demoted account cannot use it).
        if (! $user->is_admin) {
            Log::notice('Microsoft sign-in refused for a non-admin account', ['user' => $user->id]);

            return $this->fail(__('Your account does not have administrator access.'));
        }

        $user->forceFill([
            'microsoft_oid' => $oid,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        ActivityLog::record('auth.sso', null, $user->email);

        return redirect()->intended(route('dashboard'));
    }

    private function available(): bool
    {
        return AppSetting::current()->sso_enabled && $this->graph->isConfigured();
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
