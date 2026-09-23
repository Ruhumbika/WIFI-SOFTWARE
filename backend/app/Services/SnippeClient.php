<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SnippeClient
{
    public function createPayment(Order $order, Payment $payment): array
    {
        $apiKey = $this->apiKey();
        $webhookUrl = (string) config('snippe.webhook_url');
        if ((string) config('snippe.webhook_secret') === '' || !filter_var($webhookUrl, FILTER_VALIDATE_URL) || !str_starts_with($webhookUrl, 'https://')) {
            throw new RuntimeException('A Snippe webhook secret and HTTPS webhook URL are required.');
        }

        $name = trim((string) $order->customer_name);
        $parts = preg_split('/\s+/', $name, 2);
        if (count($parts) < 2 || !$parts[1] || !filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Customer name and email are required for Snippe payments.');
        }
        $digits = preg_replace('/\D+/', '', $order->customer_phone);

        $payload = [
            'payment_type' => 'mobile',
            'details' => ['amount' => $order->amount, 'currency' => 'TZS'],
            'phone_number' => $digits,
            'customer' => ['firstname' => $parts[0], 'lastname' => $parts[1], 'email' => $order->customer_email],
            'webhook_url' => $webhookUrl,
            'metadata' => ['order_id' => $order->order_number, 'order_uuid' => $order->uuid],
        ];

        return Http::acceptJson()
            ->withToken($apiKey)
            ->withHeaders(['Idempotency-Key' => $payment->idempotency_key])
            ->timeout((int) config('snippe.timeout'))
            ->post(rtrim(config('snippe.base_url'), '/').'/v1/payments', $payload)
            ->throw()
            ->json();
    }

    public function getPayment(string $reference): array
    {
        return Http::acceptJson()
            ->withToken($this->apiKey())
            ->timeout((int) config('snippe.timeout'))
            ->get(rtrim(config('snippe.base_url'), '/').'/v1/payments/'.rawurlencode($reference))
            ->throw()->json();
    }

    public function pushPayment(string $reference): array
    {
        return Http::acceptJson()
            ->withToken($this->apiKey())
            ->timeout((int) config('snippe.timeout'))
            ->post(rtrim(config('snippe.base_url'), '/').'/v1/payments/'.rawurlencode($reference).'/push')
            ->throw()->json();
    }

    public function verifyWebhook(string $rawBody, ?string $timestamp, ?string $signature): array
    {
        $secret = (string) config('snippe.webhook_secret');
        if ($secret === '' || !$timestamp || !$signature) {
            throw new RuntimeException('Missing Snippe webhook signature configuration.');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new RuntimeException('Webhook timestamp is outside the 5 minute window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid Snippe webhook signature.');
        }

        return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    }

    private function apiKey(): string
    {
        $key = (string) config('snippe.api_key');
        if ($key === '') throw new RuntimeException('SNIPPE_API_KEY is required.');
        return $key;
    }
}
