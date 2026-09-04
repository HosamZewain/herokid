<?php

namespace App\Services\Bosta;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BostaAddressCatalogService
{
    public function __construct(private BostaClient $client) {}

    /** @return array<int, array{id:string,name:string,other_name:string,label:string}> */
    public function cities(): array
    {
        return Cache::remember('bosta.address-catalog.v2.cities.'.config('bosta.country_id'), now()->addDay(), function (): array {
            return collect($this->responseList($this->client->cities(), ['cities']))
                ->map(function (array $city): array {
                    $name = trim((string) ($city['name'] ?? $city['nameEn'] ?? ''));
                    $otherName = trim((string) ($city['otherName'] ?? $city['nameAr'] ?? ''));

                    return [
                        'id' => (string) ($city['_id'] ?? $city['id'] ?? ''),
                        'name' => $name,
                        'other_name' => $otherName,
                        'label' => $this->label($otherName, $name),
                    ];
                })
                ->filter(fn (array $city): bool => $city['id'] !== '' && $city['label'] !== '')
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        });
    }

    /** @return array<int, array{id:string,name:string,other_name:string,label:string,zone_id:string,zone_name:string,zone_other_name:string}> */
    public function districts(string $cityId): array
    {
        return Cache::remember('bosta.address-catalog.v2.districts.'.$cityId, now()->addDay(), function () use ($cityId): array {
            return collect($this->responseList($this->client->districts($cityId), ['districts']))
                ->filter(fn (array $district): bool => ($district['dropOffAvailability'] ?? true) !== false)
                ->map(function (array $district): array {
                    $name = trim((string) ($district['districtName'] ?? $district['name'] ?? ''));
                    $otherName = trim((string) ($district['districtOtherName'] ?? $district['otherName'] ?? ''));

                    return [
                        'id' => (string) ($district['districtId'] ?? $district['_id'] ?? $district['id'] ?? ''),
                        'name' => $name,
                        'other_name' => $otherName,
                        'label' => $this->label($otherName, $name),
                        'zone_id' => (string) ($district['zoneId'] ?? ''),
                        'zone_name' => (string) ($district['zoneName'] ?? ''),
                        'zone_other_name' => (string) ($district['zoneOtherName'] ?? ''),
                    ];
                })
                ->filter(fn (array $district): bool => $district['id'] !== '' && $district['label'] !== '')
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        });
    }

    public function findCityById(string $cityId): ?array
    {
        return collect($this->cities())->firstWhere('id', $cityId);
    }

    public function findCityByName(string $name): ?array
    {
        $normalized = $this->normalize($name);

        return collect($this->cities())->first(fn (array $city): bool => in_array($normalized, [
            $this->normalize($city['name']),
            $this->normalize($city['other_name']),
        ], true));
    }

    public function findDistrictById(string $cityId, string $districtId): ?array
    {
        return collect($this->districts($cityId))->firstWhere('id', $districtId);
    }

    public function findDistrictByName(string $cityId, string $name): ?array
    {
        $normalized = $this->normalize($name);

        return collect($this->districts($cityId))->first(fn (array $district): bool => in_array($normalized, [
            $this->normalize($district['name']),
            $this->normalize($district['other_name']),
        ], true));
    }

    private function responseList(array $response, array $nestedKeys): array
    {
        $data = data_get($response, 'data', $response);
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['list']) && is_array($data['list'])) {
            return array_values($data['list']);
        }

        foreach ($nestedKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values($data[$key]);
            }
        }

        return array_is_list($data) ? array_values($data) : [];
    }

    private function label(string $primary, string $secondary): string
    {
        $names = array_values(array_unique(array_filter([$primary, $secondary])));

        return implode(' — ', $names);
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
