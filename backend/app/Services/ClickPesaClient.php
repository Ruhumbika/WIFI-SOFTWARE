<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClickPesaClient
{
    public function assertConfigured(): void
    {
        if ((string) config('clickpesa.client_id') === '' || (string) config('clickpesa.api_key') === ''
            || (string) config('clickpesa.checksum_key') === '') {
            throw new RuntimeException('ClickPesa credentials and checksum key are required.');
        }
    }

    public function initiate(Order $order, Payment $payment): array
    {
        $payload = [
            'amount' => (string) $order->amount,
            'currency' => $order->currency,
            'orderReference' => $payment->reference,
            'phoneNumber' => $order->customer_phone,
        ];
        $payload['checksum'] = $this->checksum($payload);

        return $this->request()->post($this->baseUrl().'/payments/initiate-ussd-push-request', $payload)->throw()->json();
    }

    public function payments(string $reference): array
    {
        $response = $this->request()->get($this->baseUrl().'/payments/'.rawurlencode($reference))->throw()->json();
        if (!is_array($response) || !array_is_list($response)) {
            throw new RuntimeException('Invalid ClickPesa payment status response.');
        }
        return $response;
    }

    public function verifyWebhook(array $payload): void
    {
        $provided = $payload['checksum'] ?? null;
        if (!is_string($provided) || !hash_equals($this->checksum($payload), $provided)) {
            throw new RuntimeException('Invalid ClickPesa webhook checksum.');
        }
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()->withHeaders(['Authorization' => $this->token()])
            ->timeout((int) config('clickpesa.timeout'));
    }

    private function token(): string
    {
        $this->assertConfigured();
        $cached = Cache::get('clickpesa:authorization-token');
        if (is_string($cached) && $cached !== '') return $cached;

        $clientId = (string) config('clickpesa.client_id');
        $apiKey = (string) config('clickpesa.api_key');
        $response = Http::acceptJson()->withHeaders(['client-id' => $clientId, 'api-key' => $apiKey])
            ->timeout((int) config('clickpesa.timeout'))
            ->post($this->baseUrl().'/generate-token')->throw()->json();
        $token = $response['token'] ?? null;
        if (!is_string($token) || !str_starts_with($token, 'Bearer ')) {
            throw new RuntimeException('ClickPesa returned no authorization token.');
        }
        Cache::put('clickpesa:authorization-token', $token, now()->addMinutes(50));
        return $token;
    }

    private function checksum(array $payload): string
    {
        $key = (string) config('clickpesa.checksum_key');
        if ($key === '') throw new RuntimeException('ClickPesa checksum key is required.');
        unset($payload['checksum'], $payload['checksumMethod']);
        return hash_hmac('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $key);
    }

    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) return array_map(fn($item) => is_array($item) ? $this->canonicalize($item) : $item, $value);
        ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item = $this->canonicalize($item);
        return $value;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('clickpesa.base_url'), '/');
    }
}
