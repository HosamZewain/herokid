<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\OrderAutomation;
use App\Models\OrderProcessRun;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;

/** Enrollment can drain independently from response polling and execution. */
class ProcessEnrollment
{
    public static function isPreview(array $definition): bool
    {
        return collect($definition['nodes'] ?? [])->pluck('type')->intersect(['generate_identity', 'generate_previews'])->isNotEmpty();
    }

    public static function previouslyOwned(string $checkout, int $exceptAutomation): bool
    {
        return OrderProcessRun::with('revision')->where('checkout_key', $checkout)->where('automation_id', '!=', $exceptAutomation)
            ->get()->contains(fn ($run) => self::isPreview($run->revision?->definition ?? []));
    }

    public function cutover(OrderAutomation $from, OrderAutomation $to, User $actor): void
    {
        abort_if($from->id === $to->id, 422);
        DB::transaction(function () use ($from, $to, $actor) {
            // Same order as enrollment. Old waits/holds remain owned by their original run.
            $flows = OrderAutomation::orderBy('id')->lockForUpdate()->get();
            $from = $flows->firstWhere('id', $from->id);
            $to = $flows->firstWhere('id', $to->id);
            abort_unless($from && $to && self::isPreview($from->revision->definition)
                && collect($to->revision->definition['nodes'])->contains('type', 'generate_previews'), 422);
            $from->update(['accepts_new_runs' => false]);
            $to->update(['accepts_new_runs' => true]);
            app(ProcessRuntime::class)->activate($to, $actor, true);
            AdminActivityLogger::log('order_process.enrollment_cutover', 'نقل استقبال الطلبات الجديدة؛ التنفيذ والردود السابقة مستمرة.', $to,
                ['previous_automation_id' => $from->id], $actor);
        });
    }
}
