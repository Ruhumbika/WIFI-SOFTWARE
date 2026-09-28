<?php
namespace Tests\Feature;
use App\Jobs\ProvisionPaidOrder;
use App\Models\{Business,PaymentGatewayAccount,Order,Payment,Plan,User,AdminApiToken};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus,Crypt,DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class SnippeLivePaymentTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void {
        parent::setUp();
        config()->set('snippe.portal_url','https://wifi.test'); config()->set('snippe.webhook_base_url','https://wifi.test');
        Bus::fake([ProvisionPaidOrder::class]); Http::preventStrayRequests();
    }
    private function gateway(?Business $business = null): PaymentGatewayAccount {
        $business ??= Business::where('code','RJAY_WIFI')->firstOrFail();
        return PaymentGatewayAccount::create(['uuid'=>(string)Str::uuid(),'business_id'=>$business->id,'provider'=>'snippe',
            'api_key_encrypted'=>'key-'.$business->uuid,'webhook_secret_encrypted'=>'secret-'.$business->uuid,
            'base_url'=>'https://api.snippe.sh','webhook_url'=>'https://wifi.test/api/webhooks/snippe/test','payment_profile_id'=>'prof_test','active'=>true]);
    }
    private function order(?Business $business = null): Order {
        $plan=Plan::create(['uuid'=>(string)Str::uuid(),'name'=>'Hour','code'=>Str::random(8),'price'=>500,'duration_seconds'=>3600,'rate_limit'=>'2M/2M','mikrotik_profile_name'=>'TEST_'.Str::random(8),'active'=>true]);
        if ($business) config()->set('snippe.business_code',$business->code);
        $result=$this->postJson('/api/public/orders',['plan_id'=>$plan->id,'phone'=>'0712345678','business_id'=>999])->assertCreated()->json();
        return Order::where('uuid',$result['uuid'])->firstOrFail();
    }
    private function headers(Order $order): array { return ['X-Order-Token'=>Crypt::encryptString($order->uuid)]; }
    private function sessionResponse(string $reference='sess_test'): array {
        return ['data'=>['reference'=>$reference,'status'=>'pending','amount'=>500,'currency'=>'TZS','checkout_url'=>'https://snippe.me/checkout/test','collect_email'=>false,'allowed_methods'=>['mobile_money']]];
    }
    private function start(Order $order, string $reference='sess_test'): Payment {
        Http::fake(['api.snippe.sh/*'=>Http::response($this->sessionResponse($reference),201)]);
        $this->postJson('/api/public/orders/'.$order->uuid.'/pay',[],$this->headers($order))->assertOk()->assertJsonPath('checkout_url','https://snippe.me/checkout/test');
        return $order->payments()->firstOrFail();
    }
    private function event(Payment $payment): array {
        return ['id'=>'evt_test','type'=>'payment.completed','data'=>['reference'=>'pi_test','session_reference'=>$payment->session_reference,'status'=>'completed',
            'amount'=>['value'=>500,'currency'=>'TZS'],'metadata'=>['order_uuid'=>$payment->order->uuid,'business_uuid'=>$payment->business->uuid,'payment_uuid'=>$payment->uuid,'system'=>'wifi']]];
    }
    private function webhook(PaymentGatewayAccount $gateway,array $event,?int $time=null,?string $signature=null) {
        $body=json_encode($event,JSON_THROW_ON_ERROR);$timestamp=(string)($time??time());
        return $this->call('POST','/api/webhooks/snippe/'.$gateway->uuid,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_WEBHOOK_TIMESTAMP'=>$timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE'=>$signature??hash_hmac('sha256',$timestamp.'.'.$body,$gateway->webhook_secret_encrypted)],$body);
    }
    public function test_each_business_uses_its_own_key_and_phone_only_checkout(): void {
        $a=$this->gateway();
        $b=Business::create(['uuid'=>(string)Str::uuid(),'name'=>'Business B','code'=>'B','status'=>'active']);$gb=$this->gateway($b);
        foreach ([$a,$gb] as $gateway) {
            $order=$this->order($gateway->business);$payment=$this->start($order,'sess_'.$gateway->id);
            $this->assertNull($order->customer_name);$this->assertNull($order->customer_email);$this->assertNull($order->fresh()->paid_at);
            $this->assertSame($gateway->id,$payment->payment_gateway_account_id);$this->assertSame($gateway->business_id,$payment->business_id);
            Http::assertSent(fn($r)=>$r->hasHeader('Authorization','Bearer '.$gateway->api_key_encrypted) && $r['customer']===['phone'=>'255712345678'] && $r['metadata']['business_uuid']===$gateway->business->uuid && $r['allowed_methods']===['mobile_money'] && $r['profile_id']==='prof_test');
        }
        Bus::assertNotDispatched(ProvisionPaidOrder::class);
    }
    public function test_webhook_completes_once_and_redirect_or_create_does_not_pay(): void {
        $g=$this->gateway();$o=$this->order();$p=$this->start($o);
        $this->getJson('/api/public/orders/'.$o->uuid.'?success=1',$this->headers($o))->assertOk();$this->assertNull($o->fresh()->paid_at);
        $e=$this->event($p);$this->webhook($g,$e)->assertOk();$this->webhook($g,$e)->assertOk()->assertJsonPath('duplicate',true);
        $e['id']='evt_second';$this->webhook($g,$e)->assertOk();
        $this->assertSame('completed',$p->fresh()->status);$this->assertSame('paid',$o->fresh()->status);Bus::assertDispatchedTimes(ProvisionPaidOrder::class,1);
    }
    public function test_cross_gateway_signature_timestamp_amount_currency_and_metadata_rejected(): void {
        $g=$this->gateway();$o=$this->order();$p=$this->start($o);$e=$this->event($p);
        $b=Business::create(['uuid'=>(string)Str::uuid(),'name'=>'B','code'=>'B','status'=>'active']);$gb=$this->gateway($b);
        $this->webhook($gb,$e)->assertStatus(503);
        $this->webhook($g,$e,null,'invalid')->assertStatus(400);$this->webhook($g,$e,time()-301)->assertStatus(400);
        foreach (['amount.value'=>501,'amount.currency'=>'USD','metadata.order_uuid'=>(string)Str::uuid(),'metadata.business_uuid'=>$b->uuid,'metadata.payment_uuid'=>(string)Str::uuid()] as $field=>$value) {
            $bad=$e;data_set($bad,'data.'.$field,$value);$this->webhook($g,$bad)->assertStatus(422);
        }
        $this->assertNull($o->fresh()->paid_at);Bus::assertNotDispatched(ProvisionPaidOrder::class);
    }
    public function test_payment_business_mismatch_is_rejected(): void {
        $g=$this->gateway();$o=$this->order();$p=$this->start($o);$e=$this->event($p);
        $b=Business::create(['uuid'=>(string)Str::uuid(),'name'=>'B','code'=>'B','status'=>'active']);$p->update(['business_id'=>$b->id]);
        $this->webhook($g,$e)->assertStatus(422);$this->assertNull($o->fresh()->paid_at);
    }
    public function test_failed_api_request_is_not_paid_and_retry_does_not_create_second_session(): void {
        $this->gateway();$o=$this->order();Http::fake(['*'=>Http::response(['message'=>'upstream secret should not be exposed'],500)]);
        $this->postJson('/api/public/orders/'.$o->uuid.'/pay',[],$this->headers($o))->assertStatus(502)->assertDontSee('upstream secret');
        $this->postJson('/api/public/orders/'.$o->uuid.'/pay',[],$this->headers($o))->assertStatus(409);
        Http::assertSentCount(1);$this->assertNull($o->fresh()->paid_at);$this->assertSame(1,$o->payments()->count());
    }
    public function test_missing_configuration_returns_safe_error(): void {
        $o=$this->order();$this->postJson('/api/public/orders/'.$o->uuid.'/pay',[],$this->headers($o))->assertStatus(503);Http::assertNothingSent();$this->assertNull($o->fresh()->paid_at);
    }
    public function test_session_is_reused_and_no_direct_payment_is_initiated(): void {
        $this->gateway();$o=$this->order();$p=$this->start($o);
        $this->postJson('/api/public/orders/'.$o->uuid.'/pay',[],$this->headers($o))->assertOk();
        Http::assertSentCount(2);Http::assertSent(fn($r)=>$r->method()==='GET'&&str_ends_with($r->url(),'/api/v1/sessions/sess_test'));$this->assertSame(1,$o->payments()->count());
    }
    public function test_profile_requiring_email_and_untrusted_checkout_url_are_rejected(): void {
        $this->gateway();$o=$this->order();$data=$this->sessionResponse();$data['data']['collect_email']=true;
        Http::fake(['*'=>Http::response($data)]);$this->postJson('/api/public/orders/'.$o->uuid.'/pay',[],$this->headers($o))->assertStatus(502);$this->assertNull($o->fresh()->paid_at);
        $o2=$this->order();$data=$this->sessionResponse('sess_other');$data['data']['checkout_url']='https://untrusted.test/checkout';Http::fake(['*'=>Http::response($data)]);
        $this->postJson('/api/public/orders/'.$o2->uuid.'/pay',[],$this->headers($o2))->assertStatus(502);
    }
    public function test_merchant_secrets_are_encrypted_and_not_serialized(): void {
        $g=$this->gateway();$raw=DB::table('payment_gateway_accounts')->where('id',$g->id)->first();
        $this->assertNotSame($g->api_key_encrypted,$raw->api_key_encrypted);$this->assertNotSame($g->webhook_secret_encrypted,$raw->webhook_secret_encrypted);
        $u=User::create(['name'=>'Admin','email'=>'admin@example.test','password'=>'test']);$token='test-admin';AdminApiToken::create(['user_id'=>$u->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour()]);
        $this->getJson('/api/admin/businesses/'.$g->business->uuid,['Authorization'=>'Bearer '.$token])->assertOk()->assertJsonPath('gateway_accounts.0.api_key_configured',true)->assertDontSee($g->api_key_encrypted)->assertDontSee($g->webhook_secret_encrypted);
        $this->getJson('/api/admin/businesses')->assertUnauthorized();
    }
    public function test_client_rejects_a_different_business_gateway_before_http(): void {
        $g=$this->gateway();$o=$this->order();$p=$this->start($o);
        $b=Business::create(['uuid'=>(string)Str::uuid(),'name'=>'B','code'=>'B','status'=>'active']);$gb=$this->gateway($b);
        Http::fake();
        try { app(\App\Services\SnippeClient::class)->createSession($o,$p,$gb); $this->fail('Cross-business client call accepted.'); }
        catch (\RuntimeException $e) { $this->assertSame('Payment gateway does not match the order.',$e->getMessage()); }
        Http::assertNothingSent();
    }
    public function test_event_ids_are_scoped_by_merchant_and_failed_attempt_does_not_end_session(): void {
        $a=$this->gateway();$oa=$this->order();$pa=$this->start($oa,'sess_a');
        $b=Business::create(['uuid'=>(string)Str::uuid(),'name'=>'B','code'=>'B','status'=>'active']);$gb=$this->gateway($b);$ob=$this->order($b);$pb=$this->start($ob,'sess_b');
        $failed=$this->event($pa);$failed['type']='payment.failed';$failed['id']='evt_failed';$failed['data']['status']='failed';
        $this->webhook($a,$failed)->assertOk();$this->assertSame('pending',$pa->fresh()->status);
        $this->webhook($a,$this->event($pa))->assertOk();$this->webhook($gb,$this->event($pb))->assertOk();
        Bus::assertDispatchedTimes(ProvisionPaidOrder::class,2);
    }
    public function test_disabled_gateway_and_wrong_session_cannot_complete_payment(): void {
        $g=$this->gateway();$o=$this->order();$p=$this->start($o);$e=$this->event($p);
        $e['data']['session_reference']='sess_wrong';$this->webhook($g,$e)->assertStatus(503);
        $g->update(['active'=>false]);$this->webhook($g,$this->event($p))->assertStatus(400);$this->assertNull($o->fresh()->paid_at);
    }
    public function test_gateway_admin_creation_update_and_credentials_remain_private(): void {
        $u=User::create(['name'=>'Admin','email'=>'merchant-admin@example.test','password'=>'test']);$token='merchant-admin';
        AdminApiToken::create(['user_id'=>$u->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour()]);$h=['Authorization'=>'Bearer '.$token];
        $b=$this->postJson('/api/admin/businesses',['name'=>'Merchant B','code'=>'MERCHANT_B','status'=>'active'],$h)->assertCreated()->json();
        $payload=['api_key'=>'private-test-key','webhook_secret'=>'private-test-secret','base_url'=>'https://api.snippe.sh','payment_profile_id'=>'prof_b','active'=>true];
        $url='/api/admin/businesses/'.$b['uuid'].'/gateways';
        $g=$this->postJson($url,$payload,$h)->assertCreated()->assertDontSee('private-test-key')->assertDontSee('private-test-secret')->assertJsonPath('api_key_configured',true)->json();
        $this->assertStringEndsWith('/api/webhooks/snippe/'.$g['uuid'],$g['webhook_url']);
        $this->postJson($url,$payload,$h)->assertUnprocessable();
        unset($payload['api_key'],$payload['webhook_secret']);$payload['active']=false;
        $this->putJson($url.'/'.$g['uuid'],$payload,$h)->assertOk()->assertJsonPath('active',false)->assertJsonPath('api_key_configured',true);
        $payload['base_url']='https://127.0.0.1';$this->putJson($url.'/'.$g['uuid'],$payload,$h)->assertUnprocessable();
    }
    public function test_ambiguous_session_recovery_verifies_metadata_and_waits_for_webhook(): void {
        $this->gateway();$o=$this->order();$data=null;Http::fake(function () use (&$data) { return Http::response($data??[], $data ? 200 : 500); });
        $this->postJson('/api/public/orders/'.$o->uuid.'/pay',[],$this->headers($o))->assertStatus(502);$p=$o->payments()->firstOrFail();
        $data=$this->sessionResponse();$data['data']['metadata']=['payment_uuid'=>$p->uuid,'order_uuid'=>$o->uuid,'business_uuid'=>$o->business->uuid,'system'=>'wifi'];
        $this->artisan('snippe:recover-session',['payment'=>$p->uuid,'session'=>'sess_test'])->assertSuccessful();
        $this->assertSame('sess_test',$p->fresh()->session_reference);$this->assertNull($o->fresh()->paid_at);Bus::assertNotDispatched(ProvisionPaidOrder::class);
    }

    public function test_documented_session_event_without_id_or_metadata_is_idempotent(): void {
        $g=$this->gateway();$o=$this->order();$p=$this->start($o);$e=$this->event($p);
        unset($e['id'],$e['data']['metadata']);
        $this->webhook($g,$e)->assertOk();$this->webhook($g,$e)->assertOk()->assertJsonPath('duplicate',true);
        Bus::assertDispatchedTimes(ProvisionPaidOrder::class,1);
    }
    public function test_historical_snippe_callback_requires_explicit_merchant_mapping(): void {
        $g=$this->gateway();$o=$this->order();$p=Payment::create(['uuid'=>(string)Str::uuid(),'order_id'=>$o->id,'provider'=>'snippe','reference'=>'legacy_pi','status'=>'pending','amount'=>500,'currency'=>'TZS','idempotency_key'=>'legacy-id']);
        $e=$this->event($p);$e['data']['reference']='legacy_pi';unset($e['data']['session_reference']);
        $body=json_encode($e,JSON_THROW_ON_ERROR);$stamp=(string)time();$server=['CONTENT_TYPE'=>'application/json','HTTP_X_WEBHOOK_TIMESTAMP'=>$stamp,'HTTP_X_WEBHOOK_SIGNATURE'=>hash_hmac('sha256',$stamp.'.'.$body,$g->webhook_secret_encrypted)];
        $this->call('POST','/api/webhooks/snippe',[],[],[],$server,$body)->assertStatus(503);
        $p->update(['payment_gateway_account_id'=>$g->id]);
        $this->call('POST','/api/webhooks/snippe',[],[],[],$server,$body)->assertOk();
        $this->assertSame('completed',$p->fresh()->status);Bus::assertDispatchedTimes(ProvisionPaidOrder::class,1);
    }

}
