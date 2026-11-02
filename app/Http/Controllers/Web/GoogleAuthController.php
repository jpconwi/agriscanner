<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Quota\QuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign in with Google using the standard OAuth 2.0 authorization-code flow.
 * - A random `state` stored in the session defends against login CSRF.
 * - The code is exchanged server-side (client secret never reaches the browser).
 * - Only Google accounts with a verified email are accepted. Role is never taken from Google.
 */
class GoogleAuthController extends Controller
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->configured()) {
            return redirect()->route('login')->with('error', 'Google sign-in is not set up on this site.');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away(self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request, QuotaService $quota): RedirectResponse
    {
        if (! $this->configured()) {
            return redirect()->route('login')->with('error', 'Google sign-in is not set up on this site.');
        }

        $expected = (string) $request->session()->pull('google_oauth_state', '');
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state', ''))) {
            return $this->fail('Your Google sign-in session expired. Please try again.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->fail('Google sign-in was cancelled.');
        }

        try {
            $token = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'code' => $request->query('code'),
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
            ]);
            if (! $token->successful() || ! $token->json('access_token')) {
                return $this->fail('Google did not accept the sign-in. Please try again.');
            }
            $info = Http::withToken($token->json('access_token'))->acceptJson()->timeout(10)->get(self::USERINFO_URL);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Could not reach Google. Please try again in a moment.');
        }

        $sub = (string) $info->json('sub');
        $email = Str::lower((string) $info->json('email'));
        if (! $info->successful() || $sub === '' || $email === '') {
            return $this->fail('Google did not return your account details.');
        }
        if ($info->json('email_verified') !== true) {
            return $this->fail('Your Google email address is not verified, so it cannot be used to sign in.');
        }

        $user = User::where('google_id', $sub)->first() ?? User::where('email', $email)->first();
        $created = false;

        if ($user && ! $user->is_active) {
            return $this->fail('This account has been disabled. Contact an administrator.');
        }

        if (! $user) {
            $user = User::create([
                'name' => Str::limit((string) ($info->json('name') ?: Str::before($email, '@')), 120, ''),
                'email' => $email,
                'password' => Str::random(48), // unusable random password; they can set one via "Forgot password"
            ]);
            $user->forceFill(['google_id' => $sub, 'email_verified_at' => now()])->save();
            $user->refresh();
            $user->profile()->create([]);
            $quota->ensureRows($user);
            $created = true;
        } elseif ($user->google_id === null) {
            // Existing password account with the same verified email: link it.
            $user->forceFill(['google_id' => $sub, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        }

        AuditLog::record($user->id, $created ? 'user.registered_google' : 'auth.login_google', 'user', $user->id);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))->with('status', $created ? 'Account created. Welcome to AgriScan!' : 'Welcome back, '.Str::before($user->name, ' ').'.');
    }

    private function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function redirectUri(): string
    {
        return config('services.google.redirect') ?: route('auth.google.callback');
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
