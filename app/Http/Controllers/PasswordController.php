<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function request(): View { return view('auth.forgot-password'); }
    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));
        return $status === Password::RESET_LINK_SENT ? back()->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }
    public function reset(string $token, Request $request): View { return view('auth.reset-password', ['token' => $token, 'email' => $request->email]); }
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', 'min:8']]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }
}
