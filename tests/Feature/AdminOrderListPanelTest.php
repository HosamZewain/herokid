<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminOrderListPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panels_require_current_permissions_and_keep_normal_forms_and_links(): void
    {
        Http::preventStrayRequests();
        $order = Order::create(['parent_name' => 'Synthetic', 'order_number' => 'SYNTHETIC-PANEL', 'status' => 'new', 'delivery_details' => ['phone' => '01000000000']]);
        $url = route('admin.orders.groups.list-panel', [$order, 'workflow']);
        $this->getJson($url)->assertUnauthorized();
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->getJson($url)->assertOk()->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')->json('html');
        $this->assertStringContainsString('data-workflow-status-form', $html);
        $this->assertStringContainsString(route('admin.orders.groups.workflow-statuses', $order), $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('<script', $html);
        if (getenv('HK_LIST_PANEL_RENDER') && app()->environment('testing')) {
            file_put_contents('/results/index.html', $this->get(route('admin.orders.index'))->getContent());
            file_put_contents('/results/workflow.html', $html);
            file_put_contents('/results/messages.html', $this->getJson(route('admin.orders.groups.list-panel', [$order, 'messages']))->json('html'));
        }
        $admin->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $admin->unsetRelation('permissions');
        $this->getJson($url)->assertForbidden();
        $this->getJson(route('admin.orders.groups.list-panel', [$order, 'messages']))->assertOk();
        $order->delete();
        $this->getJson(route('admin.orders.groups.list-panel', [$order, 'messages']))->assertNotFound();
    }
}
