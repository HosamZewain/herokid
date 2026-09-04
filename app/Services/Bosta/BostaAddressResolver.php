<?php

namespace App\Services\Bosta;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BostaAddressResolver
{
    public function __construct(private BostaClient $client) {}

    /** @return array{city:string,cityId:string,districtName:string,firstLine:string,secondLine:?string} */
    public function resolve(array $delivery): array
    {
        $governorate = trim((string) data_get($delivery, 'governorate'));
        $district = trim((string) (data_get($delivery, 'city') ?: data_get($delivery, 'area')));
        $firstLine = trim((string) (data_get($delivery, 'street') ?: data_get($delivery, 'address')));
        $secondLine = trim((string) data_get($delivery, 'address_details'));

        if ($governorate === '' || $district === '' || mb_strlen($firstLine) < 6) {
            throw ValidationException::withMessages([
                'order' => 'بيانات المحافظة أو المدينة أو عنوان الشارع غير مكتملة لإنشاء شحنة Bosta.',
            ]);
        }

        $city = collect($this->cities())->first(function (array $candidate) use ($governorate): bool {
            $names = Arr::only($candidate, ['name', 'otherName', 'nameAr', 'nameEn']);

            return collect($names)->contains(fn ($name): bool => $this->normalize((string) $name) === $this->normalize($governorate));
        });

        if (! $city || blank($city['_id'] ?? $city['id'] ?? null)) {
            throw ValidationException::withMessages([
                'order' => 'تعذر مطابقة المحافظة مع مدن Bosta. راجع عنوان الطلب أو إعدادات Bosta.',
            ]);
        }

        return [
            'city' => (string) ($city['name'] ?? $governorate),
            'cityId' => (string) ($city['_id'] ?? $city['id']),
            'districtName' => $district,
            'firstLine' => $firstLine,
            'secondLine' => $secondLine !== '' ? $secondLine : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function cities(): array
    {
        return Cache::remember('bosta.cities.'.config('bosta.country_id'), now()->addDay(), function (): array {
            $response = $this->client->cities();
            $data = data_get($response, 'data', $response);

            if (is_array($data) && isset($data['list']) && is_array($data['list'])) {
                $data = $data['list'];
            } elseif (is_array($data) && isset($data['cities']) && is_array($data['cities'])) {
                $data = $data['cities'];
            }

            return is_array($data) ? array_values($data) : [];
        });
    }

    private function normalize(string $value): string
    {
        $value = Str::of($value)
            ->replaceMatches('/[ًٌٍَُِّْـ]/u', '')
            ->replace(['محافظة', 'Governorate'], '')
            ->lower()
            ->squish()
            ->value();

        return str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $value);
    }
}
