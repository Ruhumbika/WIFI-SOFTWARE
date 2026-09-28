<?php
namespace App\Http\Controllers;
use App\Models\Business;
use App\Models\PaymentGatewayAccount;
use App\Services\GatewayConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class BusinessController extends Controller
{
    public function index() { return Business::orderBy('id')->paginate(30); }
    public function show(Business $business) { return $business->load('gatewayAccounts'); }
    public function store(Request $request) {
        $data = $this->data($request); $data['uuid']=(string) Str::uuid();
        return response()->json(Business::create($data),201);
    }
    public function update(Request $request, Business $business) { $business->update($this->data($request,$business)); return $business; }
    private function data(Request $request, ?Business $business = null): array {
        return $request->validate(['name'=>'required|string|max:255','code'=>['required','alpha_dash','max:50',Rule::unique('businesses','code')->ignore($business?->id)],'status'=>'required|in:active,inactive']);
    }
    public function storeGateway(Request $request, Business $business, GatewayConfiguration $configuration) {
        return response()->json($configuration->save($business,$request->only(['api_key','webhook_secret','base_url','payment_profile_id','active'])),201);
    }
    public function updateGateway(Request $request, Business $business, PaymentGatewayAccount $gateway, GatewayConfiguration $configuration) {
        abort_unless($gateway->business_id === $business->id,404);
        return $configuration->save($business,$request->only(['api_key','webhook_secret','base_url','payment_profile_id','active']),$gateway);
    }
}
