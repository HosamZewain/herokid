<?php

namespace App\Services\Bosta;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BostaClient
{
    public function createDelivery(array $payload): array
    {
        return $this->request()->post('/deliveries?apiVersion=1', $payload)->throw()->json();
    }

    public function createPickup(array $payload): array
    {
        return $this->request()->post('/pickups', $payload)->throw()->json();
    }

    public function searchPickups(int $page = 0, int $limit = 50): array
    {
        return $this->request()->get('/pickups/search', [
            'page' => $page,
            'limit' => $limit,
            'businessLocationId' => (string) config('bosta.business_location_id'),
        ])->throw()->json();
    }

    public function pickup(string $id): array
    {
        return $this->request()->get('/pickups/'.rawurlencode($id))->throw()->json();
    }

    public function createAwb(array $trackingNumbers, string $language = 'ar', string $type = 'A6'): array
    {
        return $this->request()->post('/deliveries/mass-awb', [
            'trackingNumbers' => implode(',', $trackingNumbers),
            'requestedAwbType' => $type,
            'lang' => $language,
        ])->throw()->json();
    }

    public function cities(): array
    {
        return $this->request()->get('/cities', [
            'countryId' => (string) config('bosta.country_id'),
        ])->throw()->json();
    }

    public function districts(string $cityId): array
    {
        return $this->request()->get('/cities/'.rawurlencode($cityId).'/districts')->throw()->json();
    }

    private function request(): PendingRequest
    {
        if (! config('bosta.enabled') || blank(config('bosta.api_key'))) {
            throw new RuntimeException('Bosta integration is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('bosta.base_url'), '/'))
            ->acceptJson()
            ->withHeaders(['Authorization' => (string) config('bosta.api_key')])
            ->connectTimeout((int) config('bosta.connect_timeout'))
            ->timeout((int) config('bosta.timeout'))
            ->retry((int) config('bosta.retries'), 300, throw: false);
    }
}
