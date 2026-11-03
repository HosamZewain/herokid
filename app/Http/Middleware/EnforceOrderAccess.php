<?php

namespace App\Http\Middleware;

use App\Models\BookletPreview;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderPreview;
use App\Models\ProductionProject;
use App\Services\Orders\OrderAccess;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Defense in depth for bound resources, bulk IDs and non-order reporting entry points. */
class EnforceOrderAccess
{
    public function handle(Request $request, Closure $next)
    {
        $access = app(OrderAccess::class);
        if (! $access->restricted()) {
            return $next($request);
        }
        // Cross-customer consoles/reporting are all-order tools. They must not bypass
        // assigned-only mode through raw SQL, shared customer histories or shared media.
        if ($request->routeIs('admin.dashboard')) {
            return redirect()->route('admin.orders.index');
        }
        // Unrelated catalog configuration keeps its existing permissions. Shared
        // customer/report/media consoles remain all-order tools until scoped.
        if ($request->routeIs('admin.stories.*', 'admin.products.*', 'admin.pricing.*', 'admin.delivery-zones.*', 'admin.discount-codes.*', 'admin.story-library.*')) {
            return $next($request);
        }
        $boundBooklet = $request->routeIs('admin.booklet-previews.*') && $request->route('bookletPreview');
        abort_unless($request->routeIs('admin.orders.*') || $boundBooklet, 403, 'هذه الشاشة تعرض بيانات جميع الطلبات وتحتاج صلاحية مشاهدة الجميع.');
        abort_if($request->routeIs('admin.orders.existing-customers.*'), 403);

        foreach ($request->route()->parameters() as $name => $value) {
            if ($value instanceof Order) {
                $access->assert($value);
            } elseif ($value instanceof Model) {
                $this->resource($value, $access);
            } elseif (in_array($name, ['order', 'representative'], true)) {
                $access->assert(Order::withTrashed()->findOrFail($value));
            } elseif (in_array($name, ['attachment', 'preview', 'bookletPreview', 'project'], true)) {
                $model = ['attachment' => OrderAttachment::class, 'preview' => OrderPreview::class,
                    'bookletPreview' => BookletPreview::class, 'project' => ProductionProject::class][$name];
                $instance = new $model;
                $this->resource($model::where($instance->getRouteKeyName(), $value)->firstOrFail(), $access);
            }
        }
        // Validate EVERY submitted order, instead of silently filtering a partial bulk write.
        foreach (['representative_ids', 'order_ids'] as $field) {
            foreach ((array) $request->input($field, []) as $id) {
                abort_unless(is_scalar($id) && ctype_digit((string) $id), 422);
                $access->assert(Order::withTrashed()->findOrFail($id));
            }
        }
        foreach (['order_id', 'source_order_id', 'target_order_id', 'source_representative_id', 'target_representative_id'] as $field) {
            if ($request->filled($field)) {
                $access->assert(Order::withTrashed()->findOrFail($request->input($field)));
            }
        }

        return $next($request);
    }

    private function resource(Model $model, OrderAccess $access): void
    {
        if ($model instanceof BookletPreview) {
            abort_unless($model->order_id, 404);
        }
        if ($model->getAttribute('order_id')) {
            $access->assert(Order::withTrashed()->findOrFail($model->order_id));
        } elseif ($key = ($model->getAttribute('checkout_key') ?: $model->getAttribute('checkout_group_key'))) {
            $access->assert(Order::withTrashed()->where('checkout_group_key', $key)->firstOrFail());
        } elseif ($model instanceof OrderPreview) {
            $gallery = $model->productGallery;
            abort_unless($gallery, 404);
            $access->assert(Order::where('checkout_group_key', $gallery->checkout_group_key)->firstOrFail());
        }
    }
}
