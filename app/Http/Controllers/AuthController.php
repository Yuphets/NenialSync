<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PasswordResetTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;
use App\Services\OfflineOutboxService;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        } $request->session()->regenerate();

        return $this->me($request);
    }

    public function register(Request $request, OfflineOutboxService $outbox)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users', 'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]]);
        $user = User::create([...$data, 'role' => 'user', 'is_active' => true]);
        $outbox->queueUser($user);
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user], 201);
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
}
