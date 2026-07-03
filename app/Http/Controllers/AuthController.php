<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PasswordResetTicket;
use App\Models\EmailVerificationOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;
use App\Services\OfflineOutboxService;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function capabilities()
    {
        return [
            'email_delivery' => config('mail.default') !== 'log' || app()->environment('local', 'testing'),
            'google' => (bool) (config('services.google.client_id') && config('services.google.client_secret')),
        ];
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->where('is_active', true)->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }
        if ($user->role === 'user' && ! $user->email_verified_at) {
            return response()->json(['message' => 'Verify your email with the OTP sent during registration before signing in.', 'verification_required' => true, 'email' => $user->email], 403);
        }
        Auth::login($user, $request->boolean('remember'));
        PasswordResetTicket::where('user_id', $user->id)->whereNotNull('temporary_password')->update(['temporary_password' => null]);
        $request->session()->regenerate();

        return $this->me($request);
    }

    public function register(Request $request, OfflineOutboxService $outbox)
    {
        abort_if(app()->environment('production') && config('mail.default') === 'log', 503, 'Email delivery is not configured. Add production SMTP settings before customer registration.');
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:190', 'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]]);
        $email = Str::lower($data['email']);
        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing && ($existing->role !== 'user' || ! $existing->is_active || $existing->email_verified_at)) {
            return response()->json(['message' => 'An account already uses this email.'], 422);
        }
        $user = $existing ?: new User(['email' => $email, 'role' => 'user', 'is_active' => true]);
        $user->fill(['name' => $data['name'], 'password' => $data['password']])->save();
        $outbox->queueUser($user);
        $developmentCode = $this->sendOtp($user);

        return response()->json([
            'message' => $developmentCode ? 'Development mail mode: use the verification code shown below.' : 'We sent a six-digit verification code to your email.',
            'email' => $user->email, 'verification_required' => true,
            ...($developmentCode ? ['development_code' => $developmentCode] : []),
        ], 201);
    }

    public function verifyOtp(Request $request, OfflineOutboxService $outbox)
    {
        $data = $request->validate(['email' => 'required|email', 'code' => 'required|digits:6']);
        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->where('role', 'user')->firstOrFail();
        if ($user->email_verified_at) {
            Auth::login($user); $request->session()->regenerate();
            return response()->json(['user' => $user]);
        }
        $otp = EmailVerificationOtp::where('user_id', $user->id)->first();
        abort_unless($otp && $otp->expires_at->isFuture(), 422, 'The verification code expired. Request a new code.');
        abort_if($otp->attempts >= 5, 429, 'Too many incorrect attempts. Request a new code.');
        if (! hash_equals($otp->code_hash, $this->otpHash($data['code']))) {
            $otp->increment('attempts');
            return response()->json(['message' => 'The verification code is incorrect.'], 422);
        }
        $user->update(['email_verified_at' => now()]);
        $otp->delete();
        $outbox->queueUser($user->fresh());
        Auth::login($user); $request->session()->regenerate();
        return response()->json(['user' => $user->fresh()]);
    }

    public function resendOtp(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->whereNull('email_verified_at')->firstOrFail();
        $current = EmailVerificationOtp::where('user_id', $user->id)->first();
        abort_if($current && $current->sent_at->gt(now()->subMinute()), 429, 'Please wait one minute before requesting another code.');
        $this->sendOtp($user);
        return ['message' => 'A new verification code was sent.'];
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function password(Request $request, OfflineOutboxService $outbox)
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]]);
        $request->user()->update(['password' => Hash::make($data['password']), 'password_changed_at' => now(), 'must_change_password' => false]);
        $outbox->queueUser($request->user()->fresh());

        return response()->json(['message' => 'Password updated.']);
    }

    public function passwordTicket(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:190',
            'reason' => 'nullable|string|max:1000',
        ]);
        $email = Str::lower($data['email']);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        $ticket = PasswordResetTicket::create([
            'ticket_number' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'email' => $email,
            'reason' => $data['reason'] ?? 'User cannot access the account.',
            'status' => 'open',
            'requested_at' => now(),
        ]);

        return response()->json([
            'message' => 'Your password assistance ticket was submitted. Give the ticket number to an administrator.',
            'ticket_number' => $ticket->ticket_number,
        ], 202);
    }

    public function passwordTicketStatus(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'ticket_number' => 'required|uuid']);
        $ticket = PasswordResetTicket::where('ticket_number', $data['ticket_number'])->whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->firstOrFail();
        if ($ticket->status !== 'resolved' || ! $ticket->temporary_password) return ['status' => $ticket->status, 'message' => 'Your request is still waiting for an administrator.'];
        $password = $ticket->temporary_password;
        $ticket->update(['temporary_password_viewed_at' => now()]);
        return ['status' => 'resolved', 'temporary_password' => $password, 'message' => 'Use this temporary password to sign in, then change it immediately.'];
    }

    public function googleRedirect()
    {
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503, 'Google sign-in is not configured yet.');
        return Socialite::driver('google')->scopes(['openid', 'email', 'profile'])->redirect();
    }

    public function googleCallback(Request $request, OfflineOutboxService $outbox)
    {
        try { $google = Socialite::driver('google')->user(); }
        catch (Throwable) { return redirect('/login?oauth_error='.urlencode('Google sign-in could not be completed.')); }
        abort_unless($google->getEmail(), 422, 'Google did not provide a verified email address.');
        $email = Str::lower($google->getEmail());
        $user = User::where('google_id', $google->getId())->orWhereRaw('LOWER(email) = ?', [$email])->first();
        if ($user && $user->role !== 'user') abort(403, 'Google sign-in is available only for customer accounts.');
        if ($user && ! $user->is_active) abort(403, 'This account is disabled.');
        $user ??= new User(['email' => $email, 'role' => 'user', 'is_active' => true, 'password' => Str::random(48)]);
        $user->fill(['name' => $google->getName() ?: $email, 'google_id' => $google->getId(), 'avatar_url' => $google->getAvatar(), 'email_verified_at' => now()])->save();
        $outbox->queueUser($user);
        Auth::login($user); $request->session()->regenerate();
        return redirect('/');
    }

    private function sendOtp(User $user): ?string
    {
        $code = (string) random_int(100000, 999999);
        EmailVerificationOtp::updateOrCreate(['user_id' => $user->id], ['code_hash' => $this->otpHash($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'sent_at' => now()]);
        Mail::raw("Your Nenial verification code is {$code}. It expires in 10 minutes. If you did not register, ignore this email.", fn ($mail) => $mail->to($user->email)->subject('Your Nenial verification code'));
        return config('mail.default') === 'log' && app()->environment('local') ? $code : null;
    }

    private function otpHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
