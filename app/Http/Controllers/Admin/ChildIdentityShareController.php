<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityShare;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareManager;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareReportService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChildIdentityShareController extends Controller
{
    public function report(Request $request, ChildIdentityShareReportService $reports)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $report = $reports->build($validated['date_from'] ?? null, $validated['date_to'] ?? null);

        return view('admin.child-identities.share-report', compact('report'));
    }

    public function regenerate(Request $request, ChildIdentityShare $share, ChildIdentityShareManager $manager)
    {
        $manager->regenerate($share, $request->user());
        $this->audit($request, $share, 'regenerated', 'إعادة تجهيز بطاقات مشاركة هوية طفل.');

        return back()->with('success', 'تمت إضافة البطاقات إلى قائمة التجهيز.');
    }

    public function revoke(Request $request, ChildIdentityShare $share, ChildIdentityShareManager $manager)
    {
        $manager->revoke($share, $request->user());
        $this->audit($request, $share, 'revoked', 'إيقاف رابط مشاركة هوية طفل.');

        return back()->with('success', 'تم إيقاف الرابط العام.');
    }

    public function reenable(Request $request, ChildIdentityShare $share, ChildIdentityShareManager $manager)
    {
        $manager->reenable($share, $request->user());
        $this->audit($request, $share, 'reenabled', 'إعادة تفعيل رابط مشاركة هوية طفل.');

        return back()->with('success', 'تمت إعادة تفعيل الرابط.');
    }

    public function update(Request $request, ChildIdentityShare $share, ChildIdentityShareManager $manager)
    {
        $validated = $request->validate([
            'generation_attempt_id' => ['required', 'integer'],
            'display_child_first_name' => ['nullable', 'boolean'],
        ]);
        $identity = $share->identityRequest()->withTrashed()->firstOrFail();
        abort_if($identity->trashed(), 422);
        $attempt = ChildIdentityGenerationAttempt::query()->findOrFail($validated['generation_attempt_id']);
        $manager->createOrUpdate(
            $identity,
            $attempt,
            $request,
            (bool) ($validated['display_child_first_name'] ?? false),
            false,
            'admin',
            $request->user(),
        );
        $this->audit($request, $share, 'updated', 'تحديث المحاولة أو الاسم في مشاركة هوية طفل.');

        return back()->with('success', 'تم حفظ الإعدادات وتجهيز بطاقات جديدة.');
    }

    public function removeCards(Request $request, ChildIdentityShare $share, ChildIdentityShareManager $manager)
    {
        $request->validate([
            'confirmation' => ['required', Rule::in([(string) $share->id])],
        ]);
        $manager->removePublicCards($share, $request->user());

        return back()->with('success', 'تم حذف البطاقات العامة وإيقاف الرابط مع الاحتفاظ بسجل التدقيق.');
    }

    private function audit(Request $request, ChildIdentityShare $share, string $suffix, string $description): void
    {
        AdminActivityLogger::log(
            "child_identity_share.{$suffix}",
            $description,
            $share,
            ['identity_id' => $share->child_identity_request_id, 'share_status' => $share->fresh()->status],
            $request->user(),
            $request,
        );
    }
}
