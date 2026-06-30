<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

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

    public function register(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users', 'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]]);
        $user = User::create([...$data, 'role' => 'user', 'is_active' => true]);
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

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]]);
        $request->user()->update(['password' => Hash::make($data['password']), 'password_changed_at' => now()]);

        return response()->json(['message' => 'Password updated.']);
    }
}
