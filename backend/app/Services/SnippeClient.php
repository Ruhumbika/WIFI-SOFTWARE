<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SnippeClient
{
    public function isMock(): bool
    {
        return config('snippe.mode') !== 'live';
    }

    public function createPayment(Order $order, Payment $payment): array
    {
        if ($this->isMock()) {
            return [
                'status' => 'success',
                'code' => 201,
                'data' => [
                    'reference' => 'mock_'.$payment->uuid,
                    'status' => 'pending',
                    'payment_type' => 'mobile',
                ],
            ];
        }

        $apiKey = (string) config('snippe.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('SNIPPE_API_KEY is required in live mode.');
        }

        $name = trim((string) $order->customer_name);
        $parts = preg_split('/\s+/', $name ?: 'Hotspot Customer', 2);
        $first = $parts[0] ?: 'Hotspot';
        $last = $parts[1] ?? 'Customer';
        $digits = preg_replace('/\D+/', '', $order->customer_phone);
        $email = $order->customer_email ?: $digits.'@customer.rjay.local';

        $payload = [
            'payment_type' => 'mobile',
            'details' => ['amount' => $order->amount, 'currency' => 'TZS'],
            'phone_number' => $digits,
            'customer' => ['firstname' => $first, 'lastname' => $last, 'email' => $email],
            'webhook_url' => config('snippe.webhook_url'),
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
        if ($this->isMock()) return ['data' => ['reference' => $reference, 'status' => 'pending']];

        return Http::acceptJson()
            ->withToken((string) config('snippe.api_key'))
            ->timeout((int) config('snippe.timeout'))
            ->get(rtrim(config('snippe.base_url'), '/').'/v1/payments/'.rawurlencode($reference))
            ->throw()->json();
    }

    public function pushPayment(string $reference): array
    {
        if ($this->isMock()) return ['data' => ['reference' => $reference, 'status' => 'pending']];

        return Http::acceptJson()
            ->withToken((string) config('snippe.api_key'))
            ->timeout((int) config('snippe.timeout'))
            ->post(rtrim(config('snippe.base_url'), '/').'/v1/payments/'.rawurlencode($reference).'/push')
            ->throw()->json();
    }

    public function verifyWebhook(string $rawBody, ?string $timestamp, ?string $signature): array
    {
        if ($this->isMock()) {
            return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        }

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
}
