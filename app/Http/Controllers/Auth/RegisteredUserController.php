<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $identifier = trim((string) $request->input('login'));
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);

        $request->merge([
            'login' => $isEmail ? mb_strtolower($identifier) : Phone::normalize($identifier),
        ]);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'login' => [
                'required',
                'string',
                $isEmail ? 'email' : 'max:32',
                Rule::unique(User::class, $isEmail ? 'email' : 'phone'),
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $isEmail ? $request->login : null,
            'phone' => $isEmail ? null : $request->login,
            'password' => Hash::make($request->password),
            'last_seen_at' => now(),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
