<?php
namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SnippeClient
{
    public function assertConfigured(PaymentGatewayAccount $gateway): void
    {
        if ($gateway->provider !== 'snippe' || !$gateway->active || $gateway->business?->status !== 'active'
            || !$gateway->api_key_configured || !$gateway->webhook_secret_configured) {
            throw new RuntimeException('Payment gateway is unavailable.');
        }
        $this->assertUrl($gateway->base_url, config('snippe.allowed_api_hosts'));
        if (parse_url($gateway->base_url, PHP_URL_QUERY) || parse_url($gateway->base_url, PHP_URL_FRAGMENT)) throw new RuntimeException('Invalid API base URL.');
        if (parse_url($gateway->base_url, PHP_URL_PATH) && parse_url($gateway->base_url, PHP_URL_PATH) !== '/') throw new RuntimeException('Invalid API base URL.');
    }
    public function assertUrl(string $url, ?array $hosts = null): void
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ($hosts !== null && !in_array(strtolower($parts['host']), $hosts, true))) {
            throw new RuntimeException('Invalid payment URL.');
        }
    }
    public function checkoutUrl(string $url): string
    {
        $this->assertUrl($url, config('snippe.allowed_checkout_hosts'));
        if (!preg_match('~^/checkout/[A-Za-z0-9_-]+$~D', parse_url($url, PHP_URL_PATH) ?? '')
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Invalid checkout URL.');
        }
        return $url;
    }
    public function submitCheckout(string $url, string $phone): array
    {
        $url = $this->checkoutUrl($url);
        preg_match('~^/checkout/([A-Za-z0-9_-]+)$~D', parse_url($url, PHP_URL_PATH), $matches);
        // Keep the public payment destination fixed and separate from merchant-authenticated requests.
        $endpoint = 'https://api.snippe.sh/checkout/'.$matches[1].'/pay';
        try {
            $body = ['payment_method'=>'mobile_money', 'customer_phone'=>$phone];
            $request = Http::acceptJson()->asJson()->withoutRedirecting()->timeout(config('snippe.timeout'));
            $response = $request->post($endpoint, $body);
            if (!$response->successful()) return ['state'=>'failed'];
            $data = $response->json('data');
            if (!is_array($data) || !is_string($data['attempt_id'] ?? null) || trim($data['attempt_id']) === ''
                || !is_string($data['status'] ?? null)) return ['state'=>'unknown'];
            $attempt = array_intersect_key($data, array_flip(['attempt_id','status','payment_token','expires_at']));
            $attempt['state'] = $data['status'] === 'pending' && is_string($data['payment_token'] ?? null)
                && trim($data['payment_token']) !== '' ? 'initiated' : 'unknown';
            return $attempt;
        } catch (\Throwable $e) {
            return ['state'=>'unknown'];
        }
    }
    public function createSession(Order $order, Payment $payment, PaymentGatewayAccount $gateway): array
    {
        $this->assertConfigured($gateway);
        if ($order->business_id !== $gateway->business_id || $payment->business_id !== $gateway->business_id
            || $payment->payment_gateway_account_id !== $gateway->id || $payment->order_id !== $order->id
            || !$gateway->payment_profile_id) throw new RuntimeException('Payment gateway does not match the order.');
        $this->assertUrl($gateway->webhook_url);
        $redirect = rtrim(config('snippe.portal_url'), '/').'/?order='.rawurlencode($order->uuid).'&payment-return=1';
        $this->assertUrl($redirect);
        return $this->request($gateway, 'post', '/api/v1/sessions', [
            'amount'=>(int) $order->amount, 'currency'=>$order->currency, 'allowed_methods'=>['mobile_money'],
            'allow_custom_amount'=>false, 'profile_id'=>$gateway->payment_profile_id,
            'customer'=>['phone'=>$order->customer_phone], 'redirect_url'=>$redirect, 'webhook_url'=>$gateway->webhook_url,
            'description'=>'WiFi order '.$order->order_number,
            'metadata'=>['business_uuid'=>$gateway->business->uuid, 'order_uuid'=>$order->uuid, 'payment_uuid'=>$payment->uuid, 'system'=>'wifi'],
        ], $payment->idempotency_key);
    }
    public function getSession(PaymentGatewayAccount $gateway, string $reference): array
    {
        return $this->request($gateway, 'get', '/api/v1/sessions/'.rawurlencode($reference));
    }
    public function getPayment(PaymentGatewayAccount $gateway, string $reference): array
    {
        return $this->request($gateway, 'get', '/v1/payments/'.rawurlencode($reference));
    }
    public function pushPayment(PaymentGatewayAccount $gateway, string $reference): array
    {
        return $this->request($gateway, 'post', '/v1/payments/'.rawurlencode($reference).'/push');
    }
    private function request(PaymentGatewayAccount $gateway, string $method, string $path, array $payload = [], ?string $key = null): array
    {
        $this->assertConfigured($gateway);
        // Never throw an HTTP exception containing provider response bodies or credential-bearing requests.
        try {
            $request = Http::acceptJson()->withToken($gateway->api_key_encrypted)->withoutRedirecting()->timeout(config('snippe.timeout'));
            if ($key) $request = $request->withHeaders(['Idempotency-Key'=>$key]);
            $response = $request->$method(rtrim($gateway->base_url, '/').$path, $payload);
            if (!$response->successful() || !is_array($response->json())) throw new RuntimeException('Provider request failed.');
            return $response->json();
        } catch (\Throwable $e) { throw new RuntimeException('Payment provider is temporarily unavailable.'); }
    }
    public function verifyWebhook(PaymentGatewayAccount $gateway, string $rawBody, ?string $timestamp, ?string $signature): array
    {
        if ($gateway->provider !== 'snippe' || !$gateway->active || !$gateway->webhook_secret_configured
            || !$timestamp || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
            || !$signature || !preg_match('/^[a-f0-9]{64}$/', $signature)) throw new RuntimeException('Invalid webhook signature.');
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $gateway->webhook_secret_encrypted);
        if (!hash_equals($expected, $signature)) throw new RuntimeException('Invalid webhook signature.');
        $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event)) throw new RuntimeException('Invalid event.');
        return $event;
    }
}
