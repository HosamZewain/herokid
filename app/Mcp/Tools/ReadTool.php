<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\EnsureAgenticReadAccess;
use App\Models\AiCheckoutState;
use App\Models\Order;
use App\Services\Agentic\CapabilityRegistry;
use App\Services\Agentic\OrderContextBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class ReadTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        return EnsureAgenticReadAccess::allowed($request->user(), $this->name === 'get_payment_status');
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = ['order_id' => $schema->integer()->min(1)->required()->description('Existing HeroKid order ID; resolves its canonical checkout.')];
        if ($this->name === 'get_product_spec') {
            $fields['unit_key'] = $schema->string()->max(160)->required();
        }

        return $fields;
    }

    public function outputSchema(JsonSchema $schema): array
    {
        $unit = $schema->object(['unit_key' => $schema->string()->required(), 'capability' => $schema->string()->required()]);
        $payment = $schema->object(['positive_recorded' => $schema->boolean()->required(), 'consistent' => $schema->boolean()->required(),
            'ledger_ref' => $schema->integer()->nullable()->required(), 'review_pending' => $schema->boolean()->required()]);
        $data = match ($this->name) {
            'get_order_context' => ['context' => $schema->object(['checkout_ref' => $schema->string()->required(), 'units' => $schema->array()->items($unit)->required(),
                'hold' => $schema->boolean()->required(), 'automation_owned' => $schema->boolean()->required(), 'payment' => $payment->required()])->required()],
            'get_product_spec' => ['specification' => $unit->required()],
            'get_available_actions' => ['proposals' => $schema->array()->items($schema->object(['target_id' => $schema->string()->required(), 'decision' => $schema->string()->required(),
                'action' => $schema->string()->nullable()->required(), 'reason_code' => $schema->string()->required()]))->required(), 'execution_enabled' => $schema->boolean()->required()],
            'get_production_status' => ['units' => $schema->array()->items($unit)->required()],
            'get_customer_interactions' => ['confirmation' => $schema->object()->required(), 'responses' => $schema->array()->items($schema->object())->required(), 'review' => $schema->object()->required()],
            'get_payment_status' => ['payment' => $payment->required(), 'paid_amount_cents' => $schema->integer()->nullable()->required()],
            'get_human_tasks' => ['proposed_tasks' => $schema->array()->items($schema->object(['target_id' => $schema->string()->required(), 'task_type' => $schema->string()->required(), 'proposed' => $schema->boolean()->required()]))->required()],
        };

        return ['schema_version' => $schema->integer()->required(), 'checkout_ref' => $schema->string()->required(),
            'fingerprint' => $schema->string()->required(), 'context_version' => $schema->integer()->nullable()->required(),
            'observation_status' => $schema->string()->enum(['current', 'unobserved_or_changed'])->required(),
            'read_only' => $schema->boolean()->required(), 'data' => $schema->object($data)->withoutAdditionalProperties()->required()];
    }

    public function toArray(): array
    {
        $value = parent::toArray();
        $value['inputSchema']['additionalProperties'] = false;
        $value['annotations'] = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        return $value;
    }

    public function handle(Request $request, OrderContextBuilder $builder, CapabilityRegistry $registry): Response|ResponseFactory
    {
        // Repeat authorization at invocation; discovery annotations are not enforcement.
        if (! $this->shouldRegister($request)) {
            return Response::error('FORBIDDEN');
        }
        $rules = ['order_id' => 'required|integer|min:1'];
        if ($this->name === 'get_product_spec') {
            $rules['unit_key'] = 'required|string|max:160';
        }
        if (array_diff(array_keys($request->all()), array_keys($rules))) {
            return Response::error('INVALID_ARGUMENTS');
        }
        $args = $request->validate($rules);
        try {
            $order = Order::find($args['order_id']);
            if (! $order) {
                return Response::error('ORDER_NOT_FOUND');
            }
            $context = $builder->build($order->checkoutGroupKey());
            $state = AiCheckoutState::where('checkout_key', $builder->canonicalKey($order->checkoutGroupKey()))->first();
            $current = $state?->fingerprint === $context['fingerprint'];
            $amount = $context['operational']['paid_amount_cents'];
            unset($context['operational'], $context['representative_id']);
            $data = match ($this->name) {
                'get_order_context' => ['context' => $context],
                'get_product_spec' => ['specification' => collect($context['units'])->firstWhere('unit_key', $args['unit_key'])],
                'get_available_actions' => ['proposals' => $registry->targets($context), 'execution_enabled' => false],
                'get_production_status' => ['units' => array_map(fn ($u) => array_intersect_key($u, array_flip(['unit_key', 'child_ref', 'capability', 'order_status', 'expected_outputs', 'output_count', 'production'])), $context['units'])],
                'get_customer_interactions' => ['confirmation' => $context['confirmation'], 'responses' => $context['customer_interactions'], 'review' => $context['review']],
                'get_payment_status' => ['payment' => $context['payment'], 'paid_amount_cents' => $amount],
                'get_human_tasks' => ['proposed_tasks' => $registry->humanTasks($context)],
            };
            if ($this->name === 'get_product_spec' && ! $data['specification']) {
                return Response::error('UNIT_NOT_IN_CHECKOUT');
            }
            $result = ['schema_version' => 1, 'checkout_ref' => $context['checkout_ref'], 'fingerprint' => $context['fingerprint'],
                'context_version' => $current ? $state->context_version : null, 'observation_status' => $current ? 'current' : 'unobserved_or_changed',
                'read_only' => true, 'data' => $data];
            if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > config('agentic.max_context_bytes')) {
                return Response::error('CONTEXT_TOO_LARGE');
            }

            return Response::structured($result);
        } catch (\Throwable) {
            return Response::error('CONTEXT_UNAVAILABLE');
        }
    }
}
