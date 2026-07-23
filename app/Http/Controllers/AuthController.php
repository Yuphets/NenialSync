<?php

namespace App\Http\Controllers;

use App\Models\EmailVerificationOtp;
use App\Models\PasswordResetTicket;
use App\Models\User;
use App\Services\OfflineOutboxService;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class AuthController extends Controller
{
    public function capabilities()
    {
        return [
            'email_delivery' => $this->mailDeliveryIsConfigured(),
            'mail_from' => config('mail.from.address'),
            'google' => (bool) (config('services.google.client_id') && config('services.google.client_secret')),
            'google_redirect_uri' => config('services.google.redirect'),
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
        abort_unless($this->mailDeliveryIsConfigured(), 503, $this->mailConfigurationMessage());
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
            'resend_after' => 30,
            ...($developmentCode ? ['development_code' => $developmentCode] : []),
        ], 201);
    }

    public function verifyOtp(Request $request, OfflineOutboxService $outbox)
    {
        $data = $request->validate(['email' => 'required|email', 'code' => 'required|digits:6']);
        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->where('role', 'user')->firstOrFail();
        if ($user->email_verified_at) {
            Auth::login($user);
            $request->session()->regenerate();

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
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->fresh()]);
    }

    public function resendOtp(Request $request)
    {
        abort_unless($this->mailDeliveryIsConfigured(), 503, $this->mailConfigurationMessage());
        $data = $request->validate(['email' => 'required|email']);
        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->where('role', 'user')->where('is_active', true)->whereNull('email_verified_at')->firstOrFail();
        $current = EmailVerificationOtp::where('user_id', $user->id)->first();
        if ($current && $current->sent_at->gt(now()->subSeconds(30))) {
            $retryAfter = (int) ceil(max(1, 30 - $current->sent_at->diffInSeconds(now())));

            return response()->json(['message' => "Please wait {$retryAfter} seconds before requesting another code.", 'retry_after' => $retryAfter], 429);
        }
        $developmentCode = $this->sendOtp($user);

        return [
            'message' => $developmentCode ? 'Development mail mode: use the verification code shown below.' : 'A new verification code was sent.',
            'resend_after' => 30,
            ...($developmentCode ? ['development_code' => $developmentCode] : []),
        ];
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
        if ($ticket->status !== 'resolved' || ! $ticket->temporary_password) {
            return ['status' => $ticket->status, 'message' => 'Your request is still waiting for an administrator.'];
        }
        $password = $ticket->temporary_password;
        $ticket->update(['temporary_password_viewed_at' => now()]);

        return ['status' => 'resolved', 'temporary_password' => $password, 'message' => 'Use this temporary password to sign in, then change it immediately.'];
    }

    public function googleRedirect()
    {
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503, 'Google sign-in is not configured yet.');

        return Socialite::driver('google')->stateless()->scopes(['openid', 'email', 'profile'])->redirect();
    }

    public function googleCallback(Request $request, OfflineOutboxService $outbox)
    {
        if ($request->filled('error')) {
            return redirect('/login?oauth_error='.urlencode($request->input('error_description', 'Google sign-in was cancelled or denied.')));
        }
        try {
            $google = Socialite::driver('google')->stateless()->user();
        } catch (InvalidStateException $exception) {
            report($exception);

            return redirect('/login?oauth_error='.urlencode('The Google sign-in session expired. Please try again without changing browsers.'));
        } catch (Throwable $exception) {
            report($exception);

            return redirect('/login?oauth_error='.urlencode($this->googleOauthFailureMessage($exception)));
        }
        abort_unless($google->getEmail(), 422, 'Google did not provide a verified email address.');
        $email = Str::lower($google->getEmail());
        $user = User::where('google_id', $google->getId())->orWhereRaw('LOWER(email) = ?', [$email])->first();
        if ($user && $user->role !== 'user') {
            abort(403, 'Google sign-in is available only for customer accounts.');
        }
        if ($user && ! $user->is_active) {
            abort(403, 'This account is disabled.');
        }
        $user ??= new User(['email' => $email, 'role' => 'user', 'is_active' => true, 'password' => Str::random(48)]);
        $user->fill(['name' => $google->getName() ?: $email, 'google_id' => $google->getId(), 'avatar_url' => $google->getAvatar(), 'email_verified_at' => now()])->save();
        $outbox->queueUser($user);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    private function sendOtp(User $user): ?string
    {
        $code = (string) random_int(100000, 999999);
        $showDevelopmentCode = $this->shouldExposeDevelopmentOtp();

        try {
            if (! $this->sendOtpWithResend($user, $code)) {
                Mail::html($this->otpHtml($user, $code), fn ($mail) => $mail
                    ->to($user->email)
                    ->from(config('mail.from.address'), $this->mailFromName())
                    ->subject('Your Nenial verification code'));
            }
        } catch (Throwable $exception) {
            report($exception);
            if (! $showDevelopmentCode) {
                abort(503, $this->mailDeliveryFailureMessage($exception));
            }

            Log::warning('Verification email failed in local development mode; exposing the OTP in the response instead.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
            ]);
        }

        EmailVerificationOtp::updateOrCreate(['user_id' => $user->id], ['code_hash' => $this->otpHash($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'sent_at' => now()]);

        return $showDevelopmentCode ? $code : null;
    }

    private function googleOauthFailureMessage(Throwable $exception): string
    {
        $base = 'Google sign-in could not be completed.';
        $hint = 'Verify the Google client ID, client secret, and authorized redirect URI: '.config('services.google.redirect');

        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = (string) $exception->getResponse()->getBody();
            $payload = json_decode($body, true);
            $error = data_get($payload, 'error');
            $description = data_get($payload, 'error_description');

            if ($error || $description) {
                Log::warning('Google OAuth token exchange failed.', [
                    'error' => $error,
                    'description' => $description,
                    'redirect_uri' => config('services.google.redirect'),
                ]);

                return trim("{$base} Google returned ".trim($error.' '.$description).". {$hint}");
            }
        }

        Log::warning('Google OAuth sign-in failed before profile retrieval.', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'redirect_uri' => config('services.google.redirect'),
        ]);

        return "{$base} {$hint}";
    }

    private function sendOtpWithResend(User $user, string $code): bool
    {
        $apiKey = config('services.resend.key');
        if (! $apiKey) {
            return false;
        }

        $from = config('mail.from.address');
        abort_if($this->looksLikePlaceholder($from), 503, 'Email delivery needs a verified MAIL_FROM_ADDRESS before verification codes can be sent.');

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post('https://api.resend.com/emails', [
                'from' => $this->mailFromName().' <'.$from.'>',
                'to' => [$user->email],
                'subject' => 'Your Nenial verification code',
                'text' => $this->otpText($user, $code),
                'html' => $this->otpHtml($user, $code),
            ]);

        if ($response->failed()) {
            Log::warning('Resend OTP delivery failed.', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            $response->throw();
        }

        return true;
    }

    private function otpText(User $user, string $code): string
    {
        return "Hi {$user->name},\n\nYour Nenial verification code is {$code}. It expires in 10 minutes.\n\nOpen Nenial: ".$this->verificationUrl($user)."\n\nIf you did not register, ignore this email.";
    }

    private function otpHtml(User $user, string $code): string
    {
        $url = e($this->verificationUrl($user));
        $name = e($user->name ?: 'there');

        return <<<HTML
<!doctype html>
<html>
<body style="margin:0;background:#f3f6f4;font-family:Arial,Helvetica,sans-serif;color:#17231e;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6f4;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #dce6df;border-radius:18px;overflow:hidden;">
        <tr><td style="padding:24px 28px;background:#0d3e28;color:#ffffff;">
          <div style="font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#8ce0ad;font-weight:700;">Nenial</div>
          <h1 style="margin:8px 0 0;font-size:26px;line-height:1.2;">Verify your customer account</h1>
        </td></tr>
        <tr><td style="padding:28px;">
          <p style="margin:0 0 16px;font-size:16px;">Hi {$name},</p>
          <p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Use this verification code to finish creating your Nenial customer account:</p>
          <div style="margin:20px 0;padding:18px;border-radius:14px;background:#e7f4ec;text-align:center;font-size:34px;letter-spacing:.18em;font-weight:800;color:#0d3e28;">{$code}</div>
          <p style="margin:0 0 22px;font-size:14px;color:#68766f;">This code expires in 10 minutes.</p>
          <p style="margin:0 0 22px;"><a href="{$url}" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#176b43;color:#ffffff;text-decoration:none;font-weight:700;">Open Nenial verification page</a></p>
          <p style="margin:0;font-size:13px;line-height:1.6;color:#68766f;">If the button does not work, copy and paste this link into your browser:<br><a href="{$url}" style="color:#176b43;">{$url}</a></p>
        </td></tr>
        <tr><td style="padding:18px 28px;background:#f7faf8;color:#68766f;font-size:12px;">If you did not register for Nenial, you can safely ignore this email.</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function mailFromName(): string
    {
        $name = trim((string) config('mail.from.name'));

        return $name === '' || strcasecmp($name, 'Laravel') === 0 ? 'Nenial' : $name;
    }

    private function verificationUrl(User $user): string
    {
        return rtrim(config('app.url'), '/').'/login?mode=verify&email='.urlencode($user->email);
    }

    private function mailDeliveryIsConfigured(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        if (app()->environment('local') && $this->shouldExposeDevelopmentOtp()) {
            return true;
        }

        if (config('services.resend.key')) {
            return ! $this->looksLikePlaceholder((string) config('mail.from.address'));
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return false;
        }

        if (config('mail.default') === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host');
            $username = (string) config('mail.mailers.smtp.username');
            $password = (string) config('mail.mailers.smtp.password');
            $from = (string) config('mail.from.address');

            return $host
                && ! in_array($host, ['mailpit', 'localhost', '127.0.0.1'], true)
                && ! $this->looksLikePlaceholder($host)
                && ! $this->looksLikePlaceholder($username)
                && ! $this->looksLikePlaceholder($password)
                && ! $this->looksLikePlaceholder($from);
        }

        return true;
    }

    private function mailConfigurationMessage(): string
    {
        if (config('mail.default') === 'smtp' && in_array((string) config('mail.mailers.smtp.host'), ['mailpit', 'localhost', '127.0.0.1'], true)) {
            return 'Email delivery is still using local Mailpit settings. Add real production SMTP settings or RESEND_API_KEY in Vercel.';
        }

        if (config('mail.default') === 'smtp') {
            foreach ([
                'MAIL_HOST' => (string) config('mail.mailers.smtp.host'),
                'MAIL_USERNAME' => (string) config('mail.mailers.smtp.username'),
                'MAIL_PASSWORD' => (string) config('mail.mailers.smtp.password'),
                'MAIL_FROM_ADDRESS' => (string) config('mail.from.address'),
            ] as $key => $value) {
                if ($this->looksLikePlaceholder($value)) {
                    return "{$key} is missing or still uses a placeholder value. Add real SMTP credentials from your email provider.";
                }
            }
        }

        if ($this->looksLikePlaceholder((string) config('mail.from.address'))) {
            return 'Email delivery needs a verified MAIL_FROM_ADDRESS before verification codes can be sent.';
        }

        return 'Email delivery is not configured. Add real production SMTP settings or RESEND_API_KEY in Vercel.';
    }

    private function mailDeliveryFailureMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $lower = Str::lower($message);
        $host = (string) config('mail.mailers.smtp.host');
        $from = (string) config('mail.from.address');

        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $payload = json_decode((string) $exception->getResponse()->getBody(), true);
            $providerMessage = data_get($payload, 'message') ?: data_get($payload, 'error.message') ?: data_get($payload, 'name');

            return $providerMessage
                ? "Verification email was rejected by the email provider: {$providerMessage}"
                : 'Verification email was rejected by the email provider. Check the sender domain and API key.';
        }

        if (str_contains($lower, '535') || str_contains($lower, 'authentication') || str_contains($lower, 'username') || str_contains($lower, 'password')) {
            return 'SMTP authentication failed. For Gmail, use the Gmail address as MAIL_USERNAME and a 16-character Google App Password as MAIL_PASSWORD.';
        }

        if (str_contains($lower, 'connection') || str_contains($lower, 'getaddrinfo') || str_contains($lower, 'could not connect') || str_contains($lower, 'timed out')) {
            return "Could not connect to SMTP host {$host}. Verify MAIL_HOST, MAIL_PORT, and MAIL_SCHEME in Vercel.";
        }

        if (str_contains($lower, 'sender') || str_contains($lower, 'from') || str_contains($lower, 'relay')) {
            return "The email provider rejected MAIL_FROM_ADDRESS {$from}. Use a verified sender address from the same provider/account.";
        }

        return 'Verification email could not be delivered. Check the SMTP/API credentials in Vercel, then redeploy.';
    }

    private function looksLikePlaceholder(?string $value): bool
    {
        $value = Str::lower(trim((string) $value));

        return $value === ''
            || $value === 'null'
            || str_starts_with($value, 'your-')
            || str_contains($value, 'example.com')
            || in_array($value, ['verified@email.com', 'username', 'password', 'secret', 'client-secret', 'client-id'], true);
    }

    private function shouldExposeDevelopmentOtp(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return true;
        }

        $smtpHost = (string) config('mail.mailers.smtp.host');

        return config('mail.default') === 'smtp'
            && in_array($smtpHost, ['mailpit', 'localhost', '127.0.0.1'], true);
    }

    private function otpHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
