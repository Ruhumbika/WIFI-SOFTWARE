<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\ClickPesaClient;
use App\Services\ClickPesaPaymentReconciler;
use Illuminate\Http\Request;
use Throwable;

class ClickPesaWebhookController extends Controller
{
    public function __invoke(Request $request, ClickPesaClient $client, ClickPesaPaymentReconciler $reconciler)
    {
        try {
            $event = $request->json()->all();
            $client->verifyWebhook($event);
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid webhook checksum.'], 400);
        }
        $reference = data_get($event, 'data.orderReference');
        if (!is_string($reference) || $reference === '') return response()->json(['message' => 'Malformed webhook.'], 422);
        $payment = Payment::where('provider', 'clickpesa')->where('reference', $reference)->first();
        if (!$payment) return response()->json(['message' => 'Payment reference is not ready.'], 503);
        try {
            $reconciler->reconcile($payment, $client);
            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Payment could not be verified.'], 503);
        }
    }
}
