<?php

namespace App\Mcp\Tools\Sales;

use App\Services\SalesAssistant\SalesCapabilities;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class SalesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return match ($this->name) {
            'search_catalog' => ['query' => $schema->string()->max(500)->required(), 'age' => $schema->integer()->min(0)->max(18),
                'gender' => $schema->string()->enum(['boy', 'girl']), 'language' => $schema->string()->enum(['ar', 'en']), 'budget_cents' => $schema->integer()->min(0)->max(10000000),
                'cursor' => $schema->integer()->min(0)->max(100000), 'kind' => $schema->string()->enum(['story', 'product', 'package'])],
            'get_catalog_item', 'get_purchase_requirements' => ['key' => $schema->string()->max(40)->required()],
            'get_store_policies' => ['topic' => $schema->string()->enum(['payment', 'delivery', 'privacy', 'photos', 'preview', 'tracking', 'library', 'identity', 'offers'])->required()],
            default => [],
        };
    }

    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;
        $tool['annotations'] = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        return $tool;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $turn = request()->attributes->get('sales_turn');
        if (! $turn) {
            return Response::error('FORBIDDEN');
        }
        try {
            return Response::structured(app(SalesCapabilities::class)->tool($turn, $this->name, $request->all()));
        } catch (\Throwable) {
            return Response::error('READ_UNAVAILABLE_OR_LIMIT_REACHED');
        }
    }
}
