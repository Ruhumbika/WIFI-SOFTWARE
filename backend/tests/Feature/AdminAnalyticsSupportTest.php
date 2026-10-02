<?php
namespace Tests\Feature;
use App\Models\{AdminApiToken,HotspotSession,Order,Payment,PaymentGatewayAccount,Plan,SupportRequest,User,Voucher};
use App\Jobs\ProvisionPaidOrder;
use App\Services\{HotspotSyncHealth,MikrotikRestClient,VoucherAccessService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus,Cache,Crypt,Http,Log};
use Illuminate\Support\Str;
use Tests\TestCase;
class AdminAnalyticsSupportTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Http::preventStrayRequests(); Bus::fake([ProvisionPaidOrder::class]); $this->travelTo(now()->setDate(2026,10,2)->setTime(12,0)); }
    private function admin(): array {
        $user=User::create(['name'=>'Admin','email'=>Str::uuid().'@example.test','password'=>'test-only']); $token=Str::random(40);
        AdminApiToken::create(['user_id'=>$user->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour()]); return ['Authorization'=>'Bearer '.$token];
    }
    private function payment(string $status='completed', array $fields=[]): Payment {
        $plan=Plan::create(['uuid'=>(string)Str::uuid(),'name'=>'Plan '.Str::random(4),'code'=>Str::random(8),'price'=>500,'currency'=>'TZS','duration_seconds'=>3600,'rate_limit'=>'2M/2M','mikrotik_profile_name'=>Str::random(8),'active'=>true]);
        $order=Order::create(['uuid'=>(string)Str::uuid(),'order_number'=>'ORD-'.Str::random(10),'plan_id'=>$plan->id,'customer_phone'=>'255712345678','amount'=>500,'currency'=>'TZS','status'=>$status==='completed' ? 'completed' : 'pending_payment','paid_at'=>$status==='completed' ? now() : null]);
        return Payment::create(array_merge(['uuid'=>(string)Str::uuid(),'order_id'=>$order->id,'provider'=>'snippe','reference'=>'REF-'.Str::random(10),'status'=>$status,'amount'=>500,'currency'=>'TZS','idempotency_key'=>Str::random(20),'completed_at'=>$status==='completed' ? now() : null],$fields));
    }
    public function test_support_history_matches_phone_scopes_business_and_hides_private_payloads(): void {
        $paid=$this->payment();$pending=$this->payment('pending');$this->payment('failed');
        $other=$this->payment();$other->order->update(['customer_phone'=>'255754123456']);
        $foreign=$this->payment();
        $business=\App\Models\Business::create(['uuid'=>(string)Str::uuid(),'code'=>'OTHER','name'=>'Other','status'=>'active']);
        $foreign->order->update(['business_id'=>$business->id]);$foreign->update(['business_id'=>$business->id]);
        $voucher=Voucher::create(['uuid'=>(string)Str::uuid(),'code'=>'HISTORY-TEST','secret'=>'private-voucher-pin','plan_id'=>$paid->order->plan_id,'order_id'=>$paid->order_id,'customer_phone'=>'255712345678','status'=>'ready']);
        $pending->events()->create(['event_id'=>'history-event','event_type'=>'payment.pending','reference'=>$pending->reference,'payload'=>['secret'=>'private-provider-value'],'processed_at'=>now()]);
        $created=$this->postJson('/api/public/support',['phone'=>'0712345678'])->assertCreated();
        $url='/api/admin/support/'.$created->json('uuid').'/history';$this->getJson($url)->assertUnauthorized();
        $response=$this->getJson($url,$this->admin())->assertOk()->assertJsonPath('payments.total',3)->assertJsonPath('vouchers.total',1)->assertJsonPath('vouchers.data.0.id',$voucher->id);
        $this->assertEqualsCanonicalizing(['completed','pending','failed'],array_column($response->json('payments.data'),'status'));
        $this->assertContains('payment.pending',array_column($response->json('timeline'),'event'));
        foreach (['private-voucher-pin','private-provider-value','provider_payload','idempotency_key','open_key'] as $private) $this->assertStringNotContainsString($private,$response->getContent());
    }
    private function signed(Payment $p, mixed $settlement, string $eventId='evt_one') {
        $gateway=$p->gatewayAccount;
        $data=['reference'=>$p->reference,'status'=>'completed','amount'=>['value'=>500,'currency'=>'TZS']];
        if ($settlement !== 'missing') $data['settlement']=$settlement;
        $body=json_encode(['id'=>$eventId,'type'=>'payment.completed','data'=>$data],JSON_THROW_ON_ERROR); $time=(string)time();
        return $this->call('POST','/api/webhooks/snippe/'.$gateway->uuid,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_WEBHOOK_TIMESTAMP'=>$time,'HTTP_X_WEBHOOK_SIGNATURE'=>hash_hmac('sha256',$time.'.'.$body,$gateway->webhook_secret_encrypted)],$body);
    }
    private function webhookPayment(): Payment {
        $p=$this->payment('pending'); $g=PaymentGatewayAccount::create(['uuid'=>(string)Str::uuid(),'business_id'=>$p->business_id,'provider'=>'snippe','api_key_encrypted'=>'test-key','webhook_secret_encrypted'=>'test-secret','base_url'=>'https://api.snippe.sh','webhook_url'=>'https://example.test/webhook','active'=>true]);
        $p->update(['payment_gateway_account_id'=>$g->id]); return $p->fresh();
    }
    private function settlement(): array { return ['gross'=>['value'=>500,'currency'=>'TZS'],'fees'=>['value'=>10,'currency'=>'TZS'],'net'=>['value'=>490,'currency'=>'TZS']]; }
    public function test_settlement_validates_signed_values_and_is_idempotent(): void {
        $p=$this->webhookPayment();$s=$this->settlement();$this->signed($p,$s)->assertOk();$this->signed($p,$s)->assertOk()->assertJsonPath('duplicate',true);
        $this->assertSame(500,$p->fresh()->gross_amount);$this->assertSame(10,$p->fresh()->fee_amount);$this->assertSame(490,$p->fresh()->net_amount);
        $this->assertSame('TZS',$p->fresh()->settlement_currency);Bus::assertDispatchedTimes(ProvisionPaidOrder::class,1);
        $s['fees']['value']=20;$s['net']['value']=480;$this->signed($p,$s,'evt_conflict')->assertStatus(422);$this->assertSame(490,$p->fresh()->net_amount);
    }
    public function test_invalid_settlements_leave_payment_unpaid(): void {
        $p=$this->webhookPayment();
        foreach (['gross.value'=>501,'fees.value'=>-1,'net.value'=>499,'fees.currency'=>'USD','net.value-string'=>'490'] as $field=>$value) {
            $s=$this->settlement(); data_set($s,$field==='net.value-string' ? 'net.value' : $field,$value);$this->signed($p,$s)->assertStatus(422);
        }
        $s=$this->settlement();unset($s['fees']);$this->signed($p,$s)->assertStatus(422);
        $this->assertSame('pending',$p->fresh()->status);Bus::assertNotDispatched(ProvisionPaidOrder::class);
    }
    public function test_missing_settlement_never_invents_fees_or_net(): void {
        $p=$this->webhookPayment();$this->signed($p,'missing')->assertOk();$this->assertNull($p->fresh()->fee_amount);$this->assertNull($p->fresh()->net_amount);$this->assertNull($p->fresh()->gross_amount);
        $this->getJson('/api/admin/payments',$this->admin())->assertOk()->assertJsonMissingPath('data.0.provider_payload');
    }
    public function test_payment_filters_combination_and_pagination_preserve_semantics(): void {
        $p=$this->payment();$old=$this->payment('completed',['completed_at'=>now()->subDays(2)]);$this->payment('pending');$oldPending=$this->payment('pending');$oldPending->timestamps=false;$oldPending->updated_at=now()->subDay();$oldPending->save();$headers=$this->admin();
        $base='/api/admin/payments';
        $this->getJson($base.'?date=today&status=completed',$headers)->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$p->id);
        $this->getJson($base.'?date=custom&from=2026-09-30&to=2026-09-30',$headers)->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$old->id);
        $this->getJson($base.'?status=pending',$headers)->assertOk()->assertJsonPath('total',2)->assertJsonPath('data.1.id',$oldPending->id);
        foreach ([$p->reference,$p->order->order_number,'255712345678'] as $term) $this->getJson($base.'?date=today&status=completed&plan_id='.$p->order->plan_id.'&search='.urlencode($term),$headers)->assertOk()->assertJsonPath('total',1);
        $page=$this->getJson($base.'?date=7d&status=completed&per_page=1',$headers)->assertOk()->assertJsonPath('total',2);
        $this->assertStringContainsString('date=7d',$page->json('next_page_url'));$this->assertStringContainsString('status=completed',$page->json('next_page_url'));
        $this->getJson($base.'?date=custom&from=2026-10-03&to=2026-10-01',$headers)->assertUnprocessable();
        $this->getJson($base.'?status=bogus',$headers)->assertUnprocessable();
    }
    public function test_tanzania_boundaries_and_dashboard_net_coverage_and_packages(): void {
        $p=$this->payment('completed',['gross_amount'=>500,'fee_amount'=>10,'net_amount'=>490,'settlement_currency'=>'TZS','completed_at'=>now()->setTime(0,0)->subHours(2)]);
        $this->payment();$this->payment('pending');$this->payment('failed');$this->payment('completed',['completed_at'=>now()->subDays(3)]);$headers=$this->admin();
        $this->getJson('/api/admin/dashboard',$headers)->assertOk()->assertJsonPath('sales_today',2)->assertJsonPath('gross_today',1000)->assertJsonPath('net_revenue_today',490)->assertJsonPath('fees_today',10)->assertJsonPath('missing_settlement',1);
        $a=$this->getJson('/api/admin/dashboard/analytics?period=today',$headers)->assertOk()->assertJsonPath('statuses.completed',2)->assertJsonPath('statuses.pending',1)->assertJsonPath('statuses.failed',1);
        $package=collect($a->json('packages'))->firstWhere('plan_id',$p->order->plan_id);$this->assertSame(1,$package['sales']);$this->assertSame(490,$package['net']);
        $this->assertSame('2026-10-02 01:00',$a->json('trend.0.bucket'));
        $this->getJson('/api/admin/dashboard/analytics?period=7d',$headers)->assertOk()->assertJsonPath('totals.0.sales',3);
        $this->getJson('/api/admin/dashboard/analytics?period=invalid',$headers)->assertUnprocessable();
    }
    public function test_one_click_support_scopes_identity_deduplicates_and_never_stores_secrets(): void {
        $p=$this->payment();$v=Voucher::create(['uuid'=>(string)Str::uuid(),'code'=>'VD-FW5NBT','secret'=>'secret-pin','plan_id'=>$p->order->plan_id,'order_id'=>$p->order_id,'status'=>'ready']);
        $headers=['X-Voucher-Recovery-Token'=>app(VoucherAccessService::class)->token($v,'redeem')];$url='/api/public/vouchers/'.$v->uuid.'/support';
        $this->postJson($url,[])->assertForbidden();
        $data=['device_mac'=>'AA:BB:CC:DD:EE:FF','connection_state'=>'offline','password'=>'secret-pin','recovery_token'=>'secret-token','provider_payload'=>['secret'=>'bad'],'link_orig'=>'https://evil.test/?token=bad'];
        $first=$this->postJson($url,$data,$headers)->assertCreated();$this->postJson($url,$data,$headers)->assertOk()->assertJsonPath('uuid',$first->json('uuid'))->assertJsonPath('duplicate',true);
        $record=SupportRequest::firstOrFail();$this->assertSame($v->id,$record->voucher_id);$this->assertSame($p->id,$record->payment_id);
        foreach (['secret-pin','secret-token','evil.test','provider_payload','password'] as $secret) $this->assertStringNotContainsString($secret,json_encode($record->getAttributes()));
        $this->getJson('/api/admin/support')->assertUnauthorized();$this->patchJson('/api/admin/support/'.$record->uuid,['status'=>'resolved'])->assertUnauthorized();
        $admin=$this->admin();
        $this->getJson('/api/admin/support?search='.urlencode($p->order->order_number),$admin)->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/admin/support?search=does-not-exist',$admin)->assertOk()->assertJsonPath('total',0);
        $this->patchJson('/api/admin/support/'.$record->uuid,['status'=>'contacted'],$admin)->assertOk();
        $this->postJson($url,[],$headers)->assertOk()->assertJsonPath('duplicate',true);
        $this->patchJson('/api/admin/support/'.$record->uuid,['status'=>'resolved'],$admin)->assertOk();
        $this->patchJson('/api/admin/support/'.$record->uuid,['status'=>'contacted'],$admin)->assertStatus(409);
        $this->postJson($url,[],$headers)->assertCreated();$this->getJson('/api/admin/dashboard',$admin)->assertOk()->assertJsonPath('open_support_requests',1);
        $new=SupportRequest::latest('id')->first();$this->patchJson('/api/admin/support/'.$new->uuid,['status'=>'cancelled'],$admin)->assertOk();
    }
    public function test_support_order_auth_anonymous_context_and_throttling(): void {
        $p=$this->payment();$url='/api/public/orders/'.$p->order->uuid.'/support';
        $this->postJson($url,[])->assertForbidden();$this->postJson($url,[],['X-Order-Token'=>Crypt::encryptString($p->order->uuid)])->assertCreated();
        $this->postJson('/api/public/support',['phone'=>'0712345678','voucher_id'=>999,'order_id'=>999])->assertCreated();
        $record=SupportRequest::latest('id')->first();$this->assertNull($record->voucher_id);$this->assertNull($record->order_id);
        $this->postJson('/api/public/support',['phone'=>'0712345678'])->assertOk()->assertJsonPath('duplicate',true);
        $before=SupportRequest::count();
        $this->postJson('/api/public/support',[])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame($before,SupportRequest::count());
        $this->postJson('/api/public/support',[])->assertStatus(429);
    }
    public function test_admin_session_search_matches_voucher_mac_and_ip(): void {
        $p=$this->payment();
        $voucher=Voucher::create(['uuid'=>(string)Str::uuid(),'code'=>'RJAY-SEARCH','secret'=>'123456','plan_id'=>$p->order->plan_id,'status'=>'active']);
        $session=HotspotSession::create(['voucher_id'=>$voucher->id,'mikrotik_id'=>'*SEARCH','mac_address'=>'AA:BB:CC:DD:EE:FF','ip_address'=>'10.10.1.55','started_at'=>now(),'last_seen_at'=>now()]);
        $headers=$this->admin();
        foreach (['RJAY-SEARCH','AA:BB:CC:DD:EE:FF','10.10.1.55'] as $term) {
            $this->getJson('/api/admin/sessions?search='.urlencode($term),$headers)->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$session->id);
        }
        $this->getJson('/api/admin/sessions?search=missing',$headers)->assertOk()->assertJsonPath('total',0);
    }
    public function test_empty_sync_success_failure_and_health_and_execution_lock(): void {
        $router=$this->mock(MikrotikRestClient::class);$router->shouldReceive('activeSessions')->once()->andReturn([]);
        $this->artisan('rjay:sync-hotspot')->assertSuccessful();$success=Cache::get('rjay:hotspot:last-successful-sync');$this->assertNotNull($success);
        Cache::put('rjay:scheduler:last-attempt',now()->toIso8601String());$this->assertSame('healthy',app(HotspotSyncHealth::class)->snapshot()['scheduler_status']);
        $this->travel(4)->minutes();$this->assertSame('delayed',app(HotspotSyncHealth::class)->snapshot()['scheduler_status']);
        $router=$this->mock(MikrotikRestClient::class);$router->shouldReceive('activeSessions')->once()->andThrow(new \RuntimeException('private upstream secret'));
        $this->artisan('rjay:sync-hotspot')->assertFailed();$this->assertSame($success,Cache::get('rjay:hotspot:last-successful-sync'));$this->assertSame('failed',Cache::get('rjay:hotspot:last-result')['state']);
        $lock=Cache::lock('rjay:hotspot:execution',60);$lock->get();$router=$this->mock(MikrotikRestClient::class);$router->shouldNotReceive('activeSessions');$this->artisan('rjay:sync-hotspot')->assertFailed();$lock->release();
    }
    public function test_individual_session_failure_is_logged_and_does_not_claim_success(): void {
        $p=$this->payment();$v=Voucher::create(['uuid'=>(string)Str::uuid(),'code'=>'VD-TEST','secret'=>'test','plan_id'=>$p->order->plan_id,'status'=>'ready']);
        $router=$this->mock(MikrotikRestClient::class);$router->shouldReceive('activeSessions')->once()->andReturn([['user'=>$v->code,'.id'=>'*1','mac-address'=>'AA:BB:CC:DD:EE:FF']]);$router->shouldReceive('bindMac')->once()->andThrow(new \RuntimeException('private secret'));
        Log::spy();$this->artisan('rjay:sync-hotspot')->assertFailed();$this->assertNull(Cache::get('rjay:hotspot:last-successful-sync'));$this->assertNull($v->fresh()->activated_at);
        Log::shouldHaveReceived('error')->with('HotSpot voucher/session operation failed; retry required.',['voucher_id'=>$v->id])->once();
    }
    public function test_schedule_has_exactly_one_sync_with_overlap_prevention(): void {
        $schedule=app(\Illuminate\Console\Scheduling\Schedule::class);$events=collect($schedule->events())->filter(fn($event)=>str_contains($event->command ?? '', 'rjay:sync-hotspot'));
        $this->assertCount(1,$events);$event=$events->first();$this->assertSame('* * * * *',$event->expression);$this->assertTrue($event->withoutOverlapping);
        Cache::forget('rjay:scheduler:last-attempt');$this->assertSame('not_recorded',app(HotspotSyncHealth::class)->snapshot()['scheduler_status']);
    }
    public function test_scheduler_callbacks_track_attempt_success_and_dashboard_reads_health(): void {
        $event=collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($e)=>str_contains($e->command ?? '', 'rjay:sync-hotspot'));
        $event->callBeforeCallbacks(app());$this->assertNotNull(Cache::get('rjay:scheduler:last-attempt'));
        $event->finish(app(),0);$this->assertNotNull(Cache::get('rjay:scheduler:last-success'));
        Cache::put('rjay:hotspot:last-successful-sync',now()->toIso8601String());
        $this->getJson('/api/admin/dashboard',$this->admin())->assertOk()->assertJsonPath('sync_health.scheduler_status','healthy')->assertJsonPath('last_sync',now()->toIso8601String());
        $this->travel(4)->minutes();$this->getJson('/api/admin/dashboard',$this->admin())->assertOk()->assertJsonPath('sync_health.scheduler_status','delayed');
    }
    public function test_independent_filters_yesterday_thirty_days_and_literal_search(): void {
        $today=$this->payment();$yesterday=$this->payment('completed',['completed_at'=>now()->subDay()]);$outside=$this->payment('completed',['completed_at'=>now()->subDays(31)]);$headers=$this->admin();
        $this->getJson('/api/admin/payments?date=yesterday',$headers)->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$yesterday->id);
        $this->getJson('/api/admin/payments?date=30d',$headers)->assertOk()->assertJsonPath('total',2);
        $this->getJson('/api/admin/payments?plan_id='.$outside->order->plan_id,$headers)->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$outside->id);
        $this->getJson('/api/admin/payments?search='.urlencode($today->reference),$headers)->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/admin/payments?search=%25',$headers)->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/admin/dashboard/analytics?period=30d',$headers)->assertOk()->assertJsonPath('totals.0.sales',2);
    }

}
