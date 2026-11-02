<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Quota\QuotaService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $user = User::where('email', $request->string('email')->lower()->toString())->first();

        // One message for unknown email and wrong password (no account enumeration); Hash::check always runs.
        $ok = Hash::check($request->input('password'), $user?->password ?? '$2y$12$0pjD4Ahs4axK.6dDgpfCbOK3o691c5XBzhpfvuXqsNPzHqSKKt1si');
        if (! $user || ! $ok) {
            AuditLog::record($user?->id, 'auth.login_failed', 'user', $user?->id);

            return back()->withInput($request->only('email'))->withErrors(['email' => 'The email or password is incorrect.']);
        }
        if (! $user->is_active) {
            return back()->withInput($request->only('email'))->with('error', 'This account has been disabled. Contact an administrator.');
        }

        Auth::login($user);
        $request->session()->regenerate(); // prevents session fixation

        return redirect()->intended(route('dashboard'))->with('status', 'Welcome back, '.Str::before($user->name, ' ').'.');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(RegisterRequest $request, QuotaService $quota): RedirectResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));
        $user->refresh(); // load DB defaults (role, is_active) // role is never taken from input
        $user->profile()->create([]);
        $quota->ensureRows($user);
        AuditLog::record($user->id, 'user.registered', 'user', $user->id);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Account created. Welcome to AgriScan!');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'You have been signed out.');
    }

    public function showForgot()
    {
        return view('auth.forgot-password');
    }

    /** Always answers the same way so the form cannot be used to discover registered emails. */
    public function sendReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('status', 'If that email is registered, a reset link has been sent. Check your inbox and spam folder.');
    }

    public function showReset(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete(); // revoke API tokens too
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'This reset link is invalid or has expired. Please request a new one.']);
        }

        return redirect()->route('login')->with('status', 'Password updated. Please sign in.');
    }
}
