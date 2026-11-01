<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\OrderAutomation;
use App\Models\OrderProcessSimulation;
use App\Models\User;
use App\Support\OrderStatusRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Deliberately has no Order model, provider, queue or storage dependency. */
class ProcessSimulator
{
    public const STATES = ['running' => 'جاري المحاكاة', 'waiting_response' => 'ينتظر ردًا تجريبيًا', 'waiting_staff' => 'ينتظر مراجعة تجريبية', 'completed' => 'اكتملت المحاكاة', 'not_matched' => 'الشروط غير منطبقة', 'blocked' => 'تحتاج مراجعة', 'cancelled' => 'أوقفت المحاكاة'];

    public function start(OrderAutomation $automation, User $actor, string $requestId, array $fixture): OrderProcessSimulation
    {
        $fixture = Validator::make($fixture, [
            'event' => 'required|in:checkout_created,status_entered',
            'status' => ['required', Rule::in(OrderStatusRegistry::keys('order'))],
            'previous_status' => ['nullable', Rule::in(OrderStatusRegistry::keys('order'))],
            'items' => 'required|array|min:1|max:20', 'items.*' => 'required|array:type,catalog_id',
            'items.*.type' => 'required|in:story,product', 'items.*.catalog_id' => 'required|integer|min:1',
        ])->validate();
        abort_unless(array_is_list($fixture['items']), 422);
        $fixture['previous_status'] = $fixture['previous_status'] ?? null;
        $fixture['items'] = array_map(fn ($item) => ['type' => $item['type'], 'catalog_id' => (int) $item['catalog_id']], $fixture['items']);

        return DB::transaction(function () use ($automation, $actor, $requestId, $fixture) {
            $automation = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
            $revision = $automation->revision;
            abort_unless($revision && ($revision->definition['schema'] ?? 1) === 2, 422, 'احفظ نسخة المسار أولًا.');
            $key = $actor->id.':'.$requestId;
            if ($existing = OrderProcessSimulation::where('request_key', $key)->first()) {
                // MySQL reorders JSON object keys; compare normalized values, not associative key order.
                abort_unless($existing->automation_id === $automation->id && $existing->revision_id === $revision->id && $existing->fixture == $fixture, 409);

                return $existing;
            }
            $definition = $revision->definition;
            $matching = $this->matching($definition, $fixture);
            $run = OrderProcessSimulation::create(['automation_id' => $automation->id, 'revision_id' => $revision->id,
                'actor_id' => $actor->id, 'request_key' => $key, 'status' => $matching ? 'running' : 'not_matched',
                'node_id' => $matching ? $definition['nodes'][0]['id'] : null, 'fixture' => $fixture,
                'context' => ['status' => $fixture['status'], 'type' => $definition['scope'] === 'checkout' ? (count(array_unique(array_column($fixture['items'], 'type'))) > 1 ? 'mixed' : $fixture['items'][0]['type']) : $definition['scope'], 'matched_items' => $matching, 'messages' => []],
                'trace' => []]);
            if ($matching) {
                $this->advance($run);
            }

            return $run;
        });
    }

    private function matching(array $definition, array $fixture): array
    {
        if ($definition['trigger'] !== $fixture['event'] || $definition['conditions']['status'] !== $fixture['status']
            || ($fixture['event'] === 'status_entered' && (empty($fixture['previous_status']) || $fixture['previous_status'] === $fixture['status']))) {
            return [];
        }
        $c = $definition['conditions'];

        return array_values(array_filter($fixture['items'], function ($item) use ($c) {
            if ($c['type'] !== 'any' && $c['type'] !== $item['type']) {
                return false;
            }
            $ids = $c[$item['type'] === 'story' ? 'story_ids' : 'product_ids'];

            return ! $ids || in_array((int) $item['catalog_id'], $ids, true);
        }));
    }

    public function respond(OrderProcessSimulation $run, int $version, string $decision): OrderProcessSimulation
    {
        return DB::transaction(function () use ($run, $version, $decision) {
            $run = OrderProcessSimulation::lockForUpdate()->findOrFail($run->id);
            abort_unless($run->version === $version, 409, 'المحاكاة اتغيرت؛ حدّث الصفحة.');
            abort_unless(in_array($run->status, ['waiting_response', 'waiting_staff'], true), 422);
            $node = collect($run->revision->definition['nodes'])->firstWhere('id', $run->node_id);
            if ($decision === '__stop') {
                $run->status = 'cancelled';
            } elseif ($run->status === 'waiting_staff') {
                abort_unless($decision === '__continue', 422);
                $run->status = 'running';
                $run->node_id = $node['next'];
            } else {
                $case = collect($node['config']['cases'])->firstWhere('key', $decision);
                abort_unless($case || $decision === '__timeout', 422);
                $context = $run->context;
                $context['decisions'][$node['id']] = $decision;
                $run->context = $context;
                $run->node_id = $decision === '__timeout' ? $node['config']['timeout_next'] : $case['next'];
                $run->status = 'running';
            }
            $this->record($run, $node, ['result' => $decision === '__timeout' ? 'simulated_timeout' : 'simulated_decision', 'decision' => $decision]);
            $run->version++;
            $run->wait_until = null;
            $run->save();
            if ($run->status === 'running') {
                $this->advance($run);
            }

            return $run;
        });
    }

    private function advance(OrderProcessSimulation $run): void
    {
        $definition = $run->revision->definition;
        $nodes = collect($definition['nodes'])->keyBy('id');
        for ($i = 0; $i < 40 && $run->status === 'running'; $i++) {
            $node = $nodes[$run->node_id] ?? null;
            if (! $node) {
                $run->status = 'blocked';
                break;
            }
            $c = $node['config'];
            $context = $run->context;
            $next = $node['next'] ?? null;
            $result = ['result' => 'simulated'];
            switch ($node['type']) {
                case 'whatsapp':
                    $variables = ['customer_name' => 'عميل تجريبي', 'order_reference' => 'DEMO-001', 'order_details' => 'محتويات تجريبية', 'order_total' => '450', 'story_names' => 'قصة وهمية', 'child_names' => 'طفل تجريبي', 'product_names' => 'منتج تجريبي', 'identity_preview_url' => 'https://example.invalid/identity-preview', 'remaining_amount' => '225'];
                    $context['messages'][$node['id']] = 'demo:'.$run->id.':'.$node['id'];
                    $result += ['template' => $c['template'], 'language' => $c['language'], 'variables' => array_map(fn ($field) => $variables[$field], $c['variables'])];
                    break;
                case 'wait_response':
                    if (! isset($context['messages'][$c['message_node']])) {
                        $run->status = 'blocked';
                        $result['result'] = 'missing_message';
                        break;
                    }
                    $run->status = 'waiting_response';
                    $run->wait_until = now()->addHours($c['timeout_hours']);
                    break;
                case 'change_status':
                    if ($context['status'] !== $c['from']) {
                        $run->status = 'blocked';
                        $result['result'] = 'state_conflict';
                        break;
                    }
                    $context['status'] = $c['to'];
                    $result += ['from' => $c['from'], 'to' => $c['to'], 'scope' => $definition['scope'], 'matched_count' => count($context['matched_items'])];
                    break;
                case 'generate_identity':
                    $context['identity_generated'] = true;
                    $result += ['generated_identities' => count(array_filter($context['matched_items'], fn ($i) => $i['type'] === 'story')), 'budget_usd' => $c['budget_usd']];
                    break;
                case 'approve_identity':
                    $wait = $nodes[$c['response_node']];
                    $approval = collect($wait['config']['cases'])->firstWhere('text', 'تأكيد الهوية');
                    if (empty($context['identity_generated']) || ! $approval || ($context['decisions'][$c['response_node']] ?? null) !== $approval['key']) {
                        $run->status = 'blocked';
                        $result['result'] = 'identity_approval_missing';
                        break;
                    }
                    $context['identity_approved'] = true;
                    $result += ['from' => $context['status'], 'to' => ProcessIdentityService::APPROVED_ORDER_STATUS];
                    $context['status'] = ProcessIdentityService::APPROVED_ORDER_STATUS;
                    break;
                case 'condition':
                    $matched = $context[$c['field']] === $c['value'];
                    $next = $c[$matched ? 'yes' : 'no'];
                    $result['branch'] = $matched ? 'yes' : 'no';
                    break;
                case 'manual_review':
                    $run->status = 'waiting_staff';
                    break;
                case 'end':
                    $run->status = 'completed';
                    break;
                default:
                    $run->status = 'blocked';
                    $result['result'] = 'unsupported_step';
            }
            $run->context = $context;
            $this->record($run, $node, $result);
            if ($run->status === 'running') {
                $run->node_id = $next;
            }
        }
        if ($run->status === 'running') {
            $run->status = 'blocked';
        }
        $run->save();
    }

    private function record(OrderProcessSimulation $run, array $node, array $result): void
    {
        $run->trace = [...$run->trace, ['node_id' => $node['id'], 'label' => $node['label'], 'type' => $node['type'], 'at' => now()->toIso8601String(), ...$result]];
    }
}
