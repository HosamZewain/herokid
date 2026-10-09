<?php

namespace App\Services\Mobile;

use App\Models\ChildProfile;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reuses the storefront's field rules; prices and photo ownership remain server-side. */
class MobileProductPersonalization
{
    public function resolve(User $user, Product $product, array $data): array
    {
        if ($product->personalization_mode !== 'collect_child_details') {
            return ['child' => null, 'snapshot' => null, 'photo_ids' => []];
        }

        $schema = ProductPersonalizationSchema::forProduct($product);
        $input = is_array($data['personalization'] ?? null) ? $data['personalization'] : [];
        $input['photo_upload_ids'] = $data['child_photo_ids'] ?? [];
        $rules = ProductPersonalizationSchema::validationRules($schema);
        $validator = Validator::make($input, $rules, ProductPersonalizationSchema::validationMessages($schema));
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $messages) {
                $key = str_starts_with($key, 'photo_upload_ids')
                    ? 'child_photo_ids'.substr($key, strlen('photo_upload_ids'))
                    : 'personalization.'.$key;
                $errors[$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $validated = $validator->validated();
        $photoIds = array_values($validated['photo_upload_ids'] ?? []);
        $child = null;
        if (! empty($data['child_profile_id'])) {
            $child = ChildProfile::query()->where('user_id', $user->id)->where('is_active', true)
                ->where('uuid', $data['child_profile_id'])->first();
            if (! $child) {
                throw ValidationException::withMessages(['child_profile_id' => 'The selected child profile is not available.']);
            }
        }
        if ($photoIds !== []) {
            if (! $child || $child->activePhotos()->whereIn('uuid', $photoIds)->count() !== count($photoIds)) {
                throw ValidationException::withMessages(['child_photo_ids' => 'Select active photos belonging to this child in your account.']);
            }
        }

        return [
            'child' => $child,
            'photo_ids' => $photoIds,
            'snapshot' => ProductPersonalizationSchema::snapshot($schema, $validated, count($photoIds)),
        ];
    }
}
