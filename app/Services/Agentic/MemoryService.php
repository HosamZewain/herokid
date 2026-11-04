<?php

namespace App\Services\Agentic;

use App\Models\AiCheckoutState;
use App\Models\AiMemory;
use App\Models\AiMemoryVersion;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Human-authored guidance, never executable policy or an Agent write tool. */
class MemoryService
{
    public const CAPABILITIES = ['story_scenes', 'single_image', 'book_cover', 'book_interior'];

    public function save(?AiMemory $memory, array $input, ?User $actor, ?int $expectedRevision = null, ?string $sourceKey = null, ?string $runId = null): AiMemory
    {
        $data = Validator::make($input, [
            'title' => 'required|string|max:160', 'guidance' => 'required|string|max:1200',
            'scope_type' => ['required', Rule::in(['global', 'capability', 'checkout'])],
            'scope_key' => 'present|nullable|string|max:255',
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'change_reason' => 'required|string|max:500',
        ])->validate();
        $data['scope_key'] = $data['scope_key'] ?? '';
        if (($data['scope_type'] === 'global' && $data['scope_key'] !== '')
            || ($data['scope_type'] === 'capability' && ! in_array($data['scope_key'], self::CAPABILITIES, true))
            || ($data['scope_type'] === 'checkout' && ! Order::where('checkout_group_key', $data['scope_key'])->exists())) {
            throw ValidationException::withMessages(['scope_key' => 'اختر نطاقًا موجودًا للقاعدة.']);
        }
        // Active guidance is deliberately small and contains no links, credentials,
        // contact details or raw transcripts. Other hard policy remains authoritative.
        if ($data['status'] === 'active' && preg_match('/https?:\/\/|\bBearer\s|\bsk-[A-Za-z0-9]|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}|(?:\+?\d[\s-]?){9,}/iu', $data['title'].' '.$data['guidance'])) {
            throw ValidationException::withMessages(['guidance' => 'اكتب قاعدة عمل عامة بدون روابط أو بيانات تواصل أو مفاتيح وصول.']);
        }

        return DB::transaction(function () use ($memory, $data, $actor, $expectedRevision, $sourceKey, $runId) {
            $this->lockAdmission();
            if ($memory) {
                $memory = AiMemory::lockForUpdate()->findOrFail($memory->id);
                if ($memory->revision !== $expectedRevision) {
                    throw ValidationException::withMessages(['revision' => 'القاعدة اتعدلت بالفعل. افتح أحدث نسخة قبل الحفظ.']);
                }
            } else {
                $memory = new AiMemory(['revision' => 0, 'source_key' => $sourceKey, 'source_run_id' => $runId]);
            }
            $reason = $data['change_reason'];
            unset($data['change_reason']);
            $memory->fill($data);
            $memory->revision++;
            $memory->updated_by = $actor?->id;
            $memory->save();
            AiMemoryVersion::create(['memory_id' => $memory->id, 'revision' => $memory->revision,
                'snapshot' => $memory->only(['title', 'guidance', 'scope_type', 'scope_key', 'status', 'source_key', 'source_run_id']),
                'change_reason' => $reason, 'actor_id' => $actor?->id, 'created_at' => now()]);
            // Hint only: the next read compares applicable memory fingerprints.
            // No AI calls, production writes, replays or changes of ownership.
            AiCheckoutState::query()->update(['dirty_at' => now(), 'updated_at' => now()]);

            return $memory;
        }, 3);
    }

    public function restore(AiMemory $memory, int $revision, int $expectedRevision, User $actor): AiMemory
    {
        $version = $memory->versions()->where('revision', $revision)->firstOrFail();

        return $this->save($memory, $version->snapshot + ['change_reason' => 'استرجاع محتوى النسخة '.$revision], $actor, $expectedRevision);
    }

    public function forContext(string $checkoutKey, array $context): array
    {
        $limit = max(1, min(100, (int) config('agentic.memory_limit', 30)));
        $rows = AiMemory::where('status', 'active')->where(function ($q) use ($checkoutKey, $context) {
            $q->where('scope_type', 'global')
                ->orWhere(fn ($q) => $q->where('scope_type', 'checkout')->where('scope_key', $checkoutKey))
                ->orWhere(fn ($q) => $q->where('scope_type', 'capability')->whereIn('scope_key', array_column($context['units'], 'capability')));
        })->orderBy('id')->limit($limit + 1)->get();
        $versions = $rows->map(fn ($m) => [$m->id, $m->revision])->all();
        $overflow = $rows->count() > $limit;

        return ['digest' => hash('sha256', json_encode($versions)), 'overflow' => $overflow,
            'entries' => $overflow ? [] : $rows->map(fn ($m) => ['id' => $m->id, 'revision' => $m->revision,
                'title' => $m->title, 'guidance' => $m->guidance, 'scope' => $m->scope_type, 'source' => $m->source_key ? 'owner_review' : 'staff_correction'])->all()];
    }

    public function importOwnerReview(?User $actor = null): int
    {
        $original = collect(json_decode(file_get_contents(resource_path('agentic/owner-memory-2026-11-03.json')), true, 16, JSON_THROW_ON_ERROR))->keyBy('key');
        $seeds = json_decode(file_get_contents(resource_path('agentic/owner-memory-2026-11-03.v2.json')), true, 16, JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($actor, $original, $seeds) {
            $this->lockAdmission();
            $count = 0;
            foreach ($seeds as $seed) {
                $key = 'owner-review:2026-11-03:'.$seed['key'];
                $memory = AiMemory::where('source_key', $key)->lockForUpdate()->first();
                $data = $seed + ['scope_type' => 'global', 'scope_key' => '', 'status' => 'active',
                    'change_reason' => 'مراجعة المالك وتوضيح التسليم الفعلي بتاريخ 3 نوفمبر 2026؛ الحالات '.$seed['cases']];
                if ($memory) {
                    $old = $original[$seed['key']];
                    if ($old['guidance'] === $seed['guidance']) {
                        continue;
                    }
                    // A current seed was already published at revision 1 (fresh
                    // install) or 2 (approved upgrade). Preserve later staff edits.
                    if ($memory->versions()->whereIn('revision', [1, 2])->get()->contains(fn ($v) => ($v->snapshot['guidance'] ?? null) === $seed['guidance'])) {
                        continue;
                    }
                    if ($memory->revision !== 1 || $memory->guidance !== $old['guidance'] || $memory->title !== $old['title']
                        || $memory->status !== 'active' || $memory->scope_type !== 'global' || $memory->scope_key !== '') {
                        throw ValidationException::withMessages(['memory' => 'قاعدة التسليم القديمة عُدّلت يدويًا؛ راجعها في الذاكرة قبل استيراد التوضيح الجديد. لم تُستبدل تعديلات الفريق.']);
                    }
                }
                try {
                    $this->save($memory, $data, $actor, $memory?->revision, sourceKey: $key);
                    $count++;
                } catch (UniqueConstraintViolationException $error) {
                    if (! AiMemory::where('source_key', $key)->exists()) {
                        throw $error;
                    }
                }
            }

            return $count;
        }, 3);
    }

    private function lockAdmission(): void
    {
        if (config('agentic.execution.enabled')) {
            // Serialize new rules as well as edits with production admission.
            // Row locks on existing memories alone do not fence inserts in MySQL
            // READ COMMITTED. Always take the admission lock before memory locks.
            DB::table('ai_production_controls')->where('id', 1)->lockForUpdate()->first();
        }
    }
}
