<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $loginInput = mb_strtolower(trim($validated['login']));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$loginInput])
            ->orWhereRaw('LOWER(username) = ?', [$loginInput])
            ->first();

        if ($user && !$user->is_active) {
            throw ValidationException::withMessages([
                'login' => 'Your account has been deactivated. Please contact the Superadmin.',
            ]);
        }

        if (!$user || !Auth::attempt([
            'id' => $user->id,
            'password' => $validated['password'],
        ], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))->with('success', "Signed in as {$user->name}.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out.');
    }
}
