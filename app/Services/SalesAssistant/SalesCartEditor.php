<?php

namespace App\Services\SalesAssistant;

use App\Models\Product;
use App\Models\Story;
use App\Services\Cart\PackageCartExpander;
use App\Services\Stories\StoryLanguageAvailability;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Services\Uploads\UploadValidationException;
use App\Support\ProductPersonalizationSchema as Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesCartEditor
{
    public function items(Request $request): array
    {
        $items = app(PackageCartExpander::class)->expand($request->session()->get('cart.items', []));
        foreach ($items as &$item) {
            $story = ($item['item_type'] ?? 'story') === 'story';
            $product = $story ? null : Product::find($item['product_id']);
            $item['edit_schema'] = $story ? Schema::legacyDefault() : ($product ? Schema::forProduct($product) : Schema::empty());
            $item['edit_fields'] = Schema::enabledFields($item['edit_schema']);
            $item['edit_values'] = $item + Schema::formValues($item['personalization_snapshot'] ?? []);
            $item['editable'] = empty($item['child_identity_request_id']) && ($story || $product?->personalization_mode === 'collect_child_details');
        } unset($item);

        return $items;
    }

    public function update(Request $request, string $key): void
    {
        $item = $this->items($request)[$key] ?? abort(404);
        abort_unless($item['editable'], 422, 'عدّل بيانات القصة المرتبطة بهذا المنتج، أو استخدم مسار الهوية الحالي.');
        $schema = $item['edit_schema'];
        $rules = Schema::validationRules($schema);
        unset($rules['photo_upload_ids'],$rules['photo_upload_ids.*']);
        $story = ($item['item_type'] ?? 'story') === 'story';
        if ($story) {
            $rules += ['gift_note' => 'nullable|string|max:500', 'parent_notes' => 'nullable|string|max:1000', 'story_language' => 'required|in:ar,en'];
        }
        $rules += ['keep' => 'nullable|array|max:10', 'keep.*' => ['string', 'regex:/^[a-f0-9]{64}$/', 'distinct'], 'photos' => 'nullable|array|max:10', 'photos.*' => 'file|max:'.(config('photo_uploads.max_size_mb', 15) * 1024)];
        $values = Validator::make($request->all(), $rules, Schema::validationMessages($schema))->validate();
        $current = collect($item['uploaded_photos'] ?? [])->mapWithKeys(fn ($p) => [hash('sha256', $p) => $p])->all();
        if (array_diff($values['keep'] ?? [], array_keys($current))) {
            throw ValidationException::withMessages(['photos' => 'اختيار الصورة غير صالح لهذا الطفل.']);
        }
        $photos = array_values(array_intersect_key($current, array_flip($values['keep'] ?? [])));
        $field = $schema['fields']['photos'] ?? ['enabled' => false, 'required' => false, 'min_files' => 0, 'max_files' => 0];
        $files = $request->file('photos', []);
        $count = count($photos) + count($files);
        if (($field['required'] && $count < $field['min_files']) || $count > $field['max_files']) {
            throw ValidationException::withMessages(['photos' => 'راجع عدد الصور المطلوب لهذا الطفل قبل الحفظ.']);
        }
        if ($story && $values['story_language'] === 'en' && ! app(StoryLanguageAvailability::class)->english(Story::findOrFail($item['story_id']))) {
            throw ValidationException::withMessages(['story_language' => 'النسخة الإنجليزية مش متاحة حاليًا.']);
        }
        $uploads = app(TemporaryPhotoUploadService::class);
        $token = $uploads->ensureSession($request)['token'];
        foreach ($files as $file) {
            $request->merge(['upload_session_token' => $token, 'upload_batch_token' => Str::random(48)]);
            try {
                $upload = $uploads->upload($request, $file);
                $photos[] = $uploads->attachIdsToCart($request, [$upload->public_id], $key)->first()->path;
            } catch (UploadValidationException $e) {
                throw ValidationException::withMessages(['photos' => $e->getMessage()]);
            }
        }
        unset($values['keep'],$values['photos']);
        $clean = array_diff_key($item, array_flip(['edit_schema', 'edit_fields', 'edit_values', 'editable']));
        $updated = [...$clean, ...$values, 'uploaded_photos' => $photos];
        if (isset($values['child_age']) && (int) $values['child_age'] !== (int) ($clean['child_age'] ?? 0)) {
            $updated['child_age_range'] = null;
        }
        if (! $story) {
            $updated['personalization_snapshot'] = Schema::snapshot($schema, $values, count($photos));
        }
        $cart = $request->session()->get('cart.items', []);
        $replace = function (array &$line) use ($key, $updated) {
            if (($line['key'] ?? '') === $key) {
                $line = $updated;
            }
        };
        foreach ($cart as &$line) {
            $replace($line);
            foreach (['package_stories', 'package_products'] as $group) {
                if (isset($line[$group])) {
                    foreach ($line[$group] as &$component) {
                        $replace($component);
                    }unset($component);
                }
            }
        }unset($line);
        // Only an explicit linked_story_key inherits identity; independent children never do.
        if ($story) {
            $inherit = function (array &$line) use ($key, $updated) {
                if (($line['linked_story_key'] ?? null) === $key && ($line['personalization_mode'] ?? '') === 'inherit_from_linked_story') {
                    $line['linked_story_snapshot'] = array_intersect_key($updated, array_flip(['story_id', 'story_title', 'child_name', 'child_age', 'child_gender']));
                }
            };
            foreach ($cart as &$line) {
                $inherit($line);
                foreach ($line['package_products'] ?? [] as $i => $component) {
                    $inherit($component);
                    $line['package_products'][$i] = $component;
                }
            }unset($line);
        }
        $request->session()->put('cart.items', $cart);
        app(SalesSessions::class)->event(app(SalesSessions::class)->own($request), 'cart_edited');
    }
}
