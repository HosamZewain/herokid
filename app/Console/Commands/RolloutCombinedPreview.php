<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\User;
use App\Services\OrderAutomation\Process\PreviewCohort;
use App\Services\OrderAutomation\Process\ProcessDefinition;
use App\Services\OrderAutomation\Process\ProcessEnrollment;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class RolloutCombinedPreview extends Command
{
    protected $signature = 'order-process:preview-rollout {from} {actor} {--apply} {--cohort=* : Exact checkout keys from the reviewed dry run}';

    protected $description = 'Inspect confirmed checkouts or switch future enrollment and admit an explicit eligible cohort.';

    public function handle(): int
    {
        $definition = app(ProcessDefinition::class)->validate(ProcessDefinition::defaults('product_review'));
        app(ProcessLivePolicy::class)->check($definition);
        $from = OrderAutomation::findOrFail($this->argument('from'));
        $actor = User::findOrFail($this->argument('actor'));
        $flow = OrderAutomation::where('name', 'المعاينة المجمعة — القصص والمنتجات')->first()
            ?? new OrderAutomation(['name' => 'المعاينة المجمعة — القصص والمنتجات', 'draft' => $definition]);
        if (! $flow->exists) {
            $flow->setRelation('revision', new OrderAutomationRevision(['definition' => $definition]));
        }
        $flow->process_activated_by = $actor->id;
        abort_unless(app(ProcessLivePolicy::class)->authorized($flow), 403);
        if (! $this->option('apply')) {
            foreach (Order::where('status', 'new_confirmed')->whereNotNull('checkout_group_key')->select('checkout_group_key')->distinct()->pluck('checkout_group_key') as $key) {
                try {
                    $snapshot = app(PreviewCohort::class)->inspect($key, $flow);
                    $this->line(json_encode(['checkout_key' => $key, 'order_ids' => $snapshot['order_ids'], 'eligible' => true]));
                } catch (Throwable $e) {
                    $reason = preg_match('/\A[a-z_]+\z/', $e->getMessage()) ? $e->getMessage() : 'inputs_require_review';
                    $this->line(json_encode(['checkout_key' => $key, 'eligible' => false, 'reason' => $reason]));
                }
            }

            return self::SUCCESS;
        }

        return Cache::lock('combined-preview-rollout', 300)->block(2, function () use ($from, $actor, $definition, $flow) {
            DB::transaction(function () use ($from, $actor, $definition, $flow) {
                if (! $flow->exists) {
                    $flow->unsetRelation('revision');
                    $flow->save();
                    $revision = OrderAutomationRevision::create(['automation_id' => $flow->id, 'definition' => $definition,
                        'checksum' => hash('sha256', json_encode($definition)), 'published_by' => $actor->id, 'created_at' => now()]);
                    $flow->update(['revision_id' => $revision->id]);
                }
                app(ProcessEnrollment::class)->cutover($from, $flow, $actor);
            });
            $flow->refresh();
            foreach (array_unique($this->option('cohort')) as $key) {
                try {
                    $run = app(PreviewCohort::class)->enroll($key, $flow);
                    $this->line(json_encode(['checkout_key' => $key, 'run_id' => $run->id, 'enrolled' => true]));
                } catch (Throwable $e) {
                    $reason = preg_match('/\A[a-z_]+\z/', $e->getMessage()) ? $e->getMessage() : 'inputs_require_review';
                    $this->line(json_encode(['checkout_key' => $key, 'enrolled' => false, 'reason' => $reason]));
                }
            }
            $this->info('Future enrollment switched; old workflow execution and replies preserved.');

            return self::SUCCESS;
        });
    }
}
