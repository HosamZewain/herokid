<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\DeliveryGovernorate;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->customerAddresses()->orderByDesc('is_default')->latest()->get()->map(fn (CustomerAddress $address) => $this->payload($address))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $address = DB::transaction(function () use ($request, $validated): CustomerAddress {
            if (($validated['is_default'] ?? false) || ! $request->user()->customerAddresses()->exists()) {
                $request->user()->customerAddresses()->update(['is_default' => false]);
                $validated['is_default'] = true;
            }

            return $request->user()->customerAddresses()->create($validated);
        });

        return response()->json(['data' => $this->payload($address)], 201);
    }

    public function show(Request $request, CustomerAddress $address): JsonResponse
    {
        $this->authorizeOwner($request, $address);

        return response()->json(['data' => $this->payload($address)]);
    }

    public function update(Request $request, CustomerAddress $address): JsonResponse
    {
        $this->authorizeOwner($request, $address);
        $validated = $this->validated($request, true);
        $countryId = $validated['delivery_country_id'] ?? $address->delivery_country_id;
        $governorateId = $validated['delivery_governorate_id'] ?? $address->delivery_governorate_id;
        abort_unless(DeliveryGovernorate::query()->whereKey($governorateId)->where('delivery_country_id', $countryId)->where('active', true)->exists(), 422, 'The selected governorate does not belong to the selected country.');
        DB::transaction(function () use ($request, $address, $validated): void {
            if ($validated['is_default'] ?? false) {
                $request->user()->customerAddresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            }
            $address->update($validated);
        });

        return response()->json(['data' => $this->payload($address->fresh())]);
    }

    public function destroy(Request $request, CustomerAddress $address): JsonResponse
    {
        $this->authorizeOwner($request, $address);
        DB::transaction(function () use ($request, $address): void {
            $wasDefault = $address->is_default;
            $address->delete();
            if ($wasDefault) {
                $request->user()->customerAddresses()->latest()->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(status: 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $request->merge(['phone' => Phone::normalize($request->input('phone'))]);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'recipient_name' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'string', 'max:32'],
            'delivery_country_id' => [$required, Rule::exists('delivery_countries', 'id')->where('active', true)],
            'delivery_governorate_id' => [$required, Rule::exists('delivery_governorates', 'id')->where('active', true)],
            'city' => [$required, 'string', 'max:255'],
            'street' => [$required, 'string', 'max:255'],
            'details' => [$required, 'string', 'max:1000'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $countryId = $validated['delivery_country_id'] ?? null;
        $governorateId = $validated['delivery_governorate_id'] ?? null;
        if ($countryId && $governorateId && ! DeliveryGovernorate::query()->whereKey($governorateId)->where('delivery_country_id', $countryId)->where('active', true)->exists()) {
            abort(422, 'The selected governorate does not belong to the selected country.');
        }

        return $validated;
    }

    private function authorizeOwner(Request $request, CustomerAddress $address): void
    {
        abort_unless($address->user_id === $request->user()->id, 404);
    }

    private function payload(CustomerAddress $address): array
    {
        return [
            'id' => $address->uuid,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'delivery_country_id' => $address->delivery_country_id,
            'delivery_governorate_id' => $address->delivery_governorate_id,
            'city' => $address->city,
            'street' => $address->street,
            'details' => $address->details,
            'delivery_instructions' => $address->delivery_instructions,
            'is_default' => $address->is_default,
        ];
    }
}
