<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Jobs\AnswerSalesAssistant;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\SalesTurn;
use App\Models\Story;
use App\Models\TemporaryPhotoUpload;
use App\Services\Cart\PackageCartExpander;
use App\Services\SalesAssistant\SalesBudget;
use App\Services\SalesAssistant\SalesCart;
use App\Services\SalesAssistant\SalesCartEditor;
use App\Services\SalesAssistant\SalesCatalog;
use App\Services\SalesAssistant\SalesChildContext;
use App\Services\SalesAssistant\SalesPrivacy;
use App\Services\SalesAssistant\SalesSessions;
use App\Services\SalesAssistant\SalesSettings;
use App\Support\ProductRecommendations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SalesAssistantController extends Controller
{
    private function check(): void
    {
        abort_unless(app(SalesSettings::class)->enabled(), 404);
    }

    public function index()
    {
        $this->check();

        return view('front.sales-assistant.index');
    }

    public function state(Request $request, SalesSessions $sessions, SalesCart $cart)
    {
        $this->check();
        $conversation = $sessions->current($request, true);
        if ($request->session()->get('sales_assistant.opened') !== $conversation->id) {
            $sessions->event($conversation, 'opened');
            $request->session()->put('sales_assistant.opened', $conversation->id);
        }

        return response()->json(['conversation' => $conversation->id, 'turns' => $sessions->turns($conversation), 'cart' => $cart->summary($request),
            'upsell_declined' => $conversation->upsell_declined, 'chat_available' => app(SalesSettings::class)->verified() && app(SalesSettings::class)->workerReady()])->header('Cache-Control', 'private, no-store');
    }

    public function message(Request $request, SalesSessions $sessions, SalesBudget $budget, SalesPrivacy $privacy, SalesCart $cart)
    {
        $this->check();
        abort_unless(app(SalesSettings::class)->verified() && app(SalesSettings::class)->workerReady(), 503, 'المحادثة الذكية غير متاحة الآن؛ تقدر تختار وتكمل الطلب من النماذج.');
        $input = $request->validate(['message' => 'required|string|max:1500', 'client_nonce' => 'required|uuid']);
        abort_if(array_diff(array_keys($request->except('_token')), ['message', 'client_nonce']), 422);
        $conversation = $sessions->own($request);
        // Idempotent retransmission gets its existing receipt without paying again.
        $existing = SalesTurn::where('conversation_id', $conversation->id)->where('client_nonce', $input['client_nonce'])->first();
        if ($existing) {
            return response()->json(['turn_id' => $existing->id, 'status' => $existing->status], 202);
        }
        $rate = 'sales-message:'.$sessions->hash($request);
        if (RateLimiter::tooManyAttempts($rate, (int) config('sales_assistant.per_minute'))) {
            $sessions->event($conversation, 'rate_limited');
            abort(429, 'استنى دقيقة قبل رسائل جديدة.');
        }
        RateLimiter::hit($rate, 60);
        $text = $privacy->redact($input['message'], $sessions->knownPrivate($request), array_column(app(SalesCatalog::class)->all(), 'title'));
        try {
            $turn = $budget->reserve($conversation, $text, $input['client_nonce']);
        } catch (ValidationException $e) {
            $sessions->event($conversation, 'message_rejected');
            throw $e;
        }
        try {
            AnswerSalesAssistant::dispatch($turn->id, $cart->summary($request));
        } catch (\Throwable) {
            $budget->settle($turn, [], 'uncertain', null);
        }

        return response()->json(['turn_id' => $turn->id, 'status' => $turn->fresh()->status], 202)->header('Cache-Control', 'private, no-store');
    }

    public function browse(Request $request, SalesCatalog $catalog, SalesSessions $sessions)
    {
        $this->check();
        $input = $request->validate(['query' => 'nullable|string|max:500', 'age' => 'nullable|integer|min:0|max:18', 'gender' => 'nullable|in:boy,girl', 'budget_cents' => 'nullable|integer|min:0|max:10000000', 'language' => 'nullable|in:ar,en']);
        $conversation = $sessions->current($request);
        $lastTurn = $conversation ? SalesTurn::where('conversation_id', $conversation->id)->latest('created_at')->orderByDesc('id')->first() : null;
        $context = $lastTurn ? app(SalesChildContext::class)->forTurn($lastTurn) : [];
        $items = $catalog->search(app(SalesPrivacy::class)->redact($input['query'] ?? ''), $context['age'] ?? $input['age'] ?? null,
            $context['gender'] ?? $input['gender'] ?? null, $input['budget_cents'] ?? null, $input['language'] ?? null);
        $items = array_values(array_filter($items, fn ($item) => $catalog->eligible($item, $context)));

        return response()->json(['cards' => $catalog->cards(array_map(fn ($i) => ['key' => $i['key'], 'reason' => 'شوف التفاصيل والمتطلبات قبل الاختيار.'], array_slice($items, 0, 3)))])->header('Cache-Control', 'private, no-store');
    }

    public function event(Request $request, SalesSessions $sessions, SalesCatalog $catalog)
    {
        $this->check();
        $input = $request->validate(['type' => 'required|in:upsell_declined,handoff,preview', 'key' => ['nullable', 'string', 'regex:/^(story|product|package):[1-9][0-9]*$/']]);
        $conversation = $sessions->own($request);
        if (! empty($input['key'])) {
            $catalog->item($input['key']);
        }
        if ($input['type'] === 'upsell_declined') {
            $conversation->update(['upsell_declined' => true]);
        }
        $sessions->event($conversation, $input['type'], $input['key'] ?? null);

        return response()->noContent();
    }

    public function upsells(Request $request, SalesSessions $sessions, SalesCatalog $catalog)
    {
        $this->check();
        $conversation = $sessions->own($request);
        if ($conversation->upsell_declined) {
            return response()->json(['cards' => []]);
        }
        $cart = app(PackageCartExpander::class)->expand($request->session()->get('cart.items', []));
        $products = app(ProductRecommendations::class)->forCartItems($cart, 2);
        $choices = $products->map(fn ($p) => ['key' => 'product:'.$p->id, 'reason' => 'إضافة اختيارية تناسب اختيارك؛ راجع الوصف والعمر المناسب.'])->all();
        $cards = $catalog->cards($choices);
        foreach ($cards as $card) {
            $sessions->event($conversation, 'upsell_recommended', $card['key']);
        }

        return response()->json(['cards' => $cards])->header('Cache-Control', 'private, no-store');
    }

    public function photo(Request $request, string $hash, SalesSessions $sessions)
    {
        $this->check();
        $sessions->own($request);
        $cart = app(PackageCartExpander::class)->expand($request->session()->get('cart.items', []));
        $path = collect($cart)->flatMap(fn ($i) => $i['uploaded_photos'] ?? [])->first(fn ($p) => hash_equals(hash('sha256', $p), $hash));
        abort_unless($path, 404);
        $upload = TemporaryPhotoUpload::where('path', $path)->first();
        $disk = Storage::disk($upload?->disk ?: config('media.private_disk', 'local'));
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function cartEdit(Request $request, SalesCartEditor $editor)
    {
        $this->check();
        app(SalesSessions::class)->own($request);
        $request->attributes->set('sales_assistant_embed', true);

        return view('front.sales-assistant.cart-edit', ['items' => $editor->items($request)]);
    }

    public function cartUpdate(Request $request, string $key, SalesCartEditor $editor)
    {
        $this->check();
        app(SalesSessions::class)->own($request);
        $editor->update($request, $key);

        return redirect()->route('sales.cart-edit', ['_sales_assistant_embed' => 1])->with('success', 'تم حفظ بيانات وصور العنصر المحدد.');
    }

    public function item(Request $request, string $kind, int $id, SalesSessions $sessions, SalesCatalog $catalog)
    {
        $this->check();
        $conversation = $sessions->own($request);
        $catalog->item($kind.':'.$id);
        $request->attributes->set('sales_assistant_embed', true);
        $request->session()->put('sales_assistant.assisted', $conversation->id);
        $sessions->event($conversation, 'selected', $kind.':'.$id);

        return match ($kind) {
            'story' => app()->call([app(StoryController::class), 'show'], ['slug' => Story::findOrFail($id)->slug]),
            'product' => app()->call([app(ShopController::class), 'show'], ['product' => Product::findOrFail($id)]),
            'package' => app()->call([app(PackageController::class), 'show'], ['pricingPackage' => PricingPackage::findOrFail($id)]),
            default => abort(404),
        };
    }
}
