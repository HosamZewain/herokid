<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /** List all admin users */
    public function index()
    {
        $admins = User::where('role', 'admin')->latest()->get();
        return view('admin.users.index', compact('admins'));
    }

    /** Show create form */
    public function create()
    {
        return view('admin.users.create');
    }

    /** Store new admin */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'admin',
            'email_verified_at' => now(),
        ]);

        return redirect()->route('admin.users.index')->with('success', 'تم إضافة المشرف بنجاح!');
    }

    /** Show edit form */
    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    /** Update admin profile */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email,' . $user->id,
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $user->name  = $validated['name'];
        $user->email = $validated['email'];

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        $message = $user->id === auth()->id()
            ? 'تم تحديث بياناتك بنجاح!'
            : 'تم تحديث بيانات المشرف بنجاح!';

        return redirect()->route('admin.users.index')->with('success', $message);
    }

    /** Delete admin (can't delete yourself) */
    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'لا يمكنك حذف حسابك الخاص!');
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'تم حذف المشرف بنجاح!');
    }
}
