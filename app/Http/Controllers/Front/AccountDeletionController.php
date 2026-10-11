<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mobile\PrivacyRequestService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountDeletionController extends Controller
{
    public function show(): View
    {
        return view('front.pages.account-deletion');
    }

    public function store(Request $request, PrivacyRequestService $privacy): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'confirmation' => ['required', 'in:DELETE_MY_ACCOUNT'],
        ], [
            'login.required' => 'أدخل رقم الموبايل أو البريد الإلكتروني المسجل في حسابك.',
            'password.required' => 'أدخل كلمة مرور حسابك لتأكيد ملكيتك له.',
            'confirmation.required' => 'وافق على طلب حذف الحساب أولًا.',
            'confirmation.in' => 'وافق على طلب حذف الحساب أولًا.',
        ]);
        $login = trim($data['login']);
        $matches = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? User::query()->where('email', mb_strtolower($login))->limit(2)->get()
            : (Phone::isValidMobile($login)
                ? User::query()->whereIn('phone', Phone::equivalentValues($login))->limit(2)->get()
                : collect());
        // Never pick an arbitrary account when legacy phone records conflict.
        $user = $matches->count() === 1 ? $matches->first() : null;
        if (! $user || $user->role === 'admin') {
            throw ValidationException::withMessages(['login' => 'تعذر تأكيد الحساب. راجع رقم الموبايل أو البريد الإلكتروني وكلمة المرور.']);
        }
        $record = $privacy->submit($user, [...$data, 'request_type' => 'account_deletion']);

        // Do not authenticate, create tokens, delete data, or expose credentials.
        return redirect()->route('account-deletion.show')->with('account_deletion_receipt', [
            'reference' => $record->uuid,
            'due_date' => $record->due_at?->format('d/m/Y'),
            'status' => in_array($record->status, ['processing', 'in_progress'], true) ? 'قيد المعالجة' : 'بانتظار المعالجة',
        ]);
    }
}
