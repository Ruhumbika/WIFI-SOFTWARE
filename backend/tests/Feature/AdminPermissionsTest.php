<?php
namespace Tests\Feature;
use App\Http\Middleware\RequireAdminPermission;
use App\Models\{AdminApiToken, Permission, User};
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Http, Log, Route};
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPermissionsTest extends TestCase
{
    use RefreshDatabase;
    private function user(array $keys = [], bool $super = false): User
    {
        $user = User::create(['name'=>'Staff','email'=>Str::uuid().'@example.test','password'=>'Strong-password123!']);
        $user->forceFill(['is_super_user'=>$super])->save();
        $user->permissions()->sync(Permission::whereIn('key',$keys)->pluck('id'));
        return $user;
    }
    private function headers(User $user): array
    {
        $plain=Str::random(80);
        AdminApiToken::create(['user_id'=>$user->id,'token_hash'=>hash('sha256',$plain),'expires_at'=>now()->addHours(12)]);
        return ['Authorization'=>'Bearer '.$plain];
    }
    private function payload(): array
    {
        return ['name'=>'New Staff','email'=>'staff@example.test','password'=>'Strong-password123!','password_confirmation'=>'Strong-password123!','permissions'=>['payments.view']];
    }
    public function test_upgrade_preserves_existing_access_passwords_and_tokens_and_safe_defaults(): void
    {
        $migration=require database_path('migrations/2026_10_05_000001_add_admin_permissions.php');
        $migration->down();
        $old=new User(['name'=>'Existing','email'=>'existing@example.test','password'=>'Strong-password123!']);
        unset($old->is_active, $old->is_super_user); $old->save();
        $headers=$this->headers($old);$hash=$old->password;
        $migration->up();$old->refresh();
        $this->assertTrue($old->is_active);$this->assertTrue($old->is_super_user);$this->assertSame($hash,$old->password);
        $this->getJson('/api/admin/me',$headers)->assertOk()->assertJsonPath('user.is_super_user',true);
        $this->postJson('/api/auth/login',['email'=>$old->email,'password'=>'Strong-password123!'])->assertOk();
        $new=$this->user()->fresh();$this->assertTrue($new->is_active);$this->assertFalse($new->is_super_user);$this->assertSame([],$new->adminAccess()['permissions']);
    }
    public function test_login_me_logout_inactive_expired_and_missing_token(): void
    {
        $user=$this->user(['payments.view']);
        $response=$this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'Strong-password123!'])->assertOk();
        $headers=['Authorization'=>'Bearer '.$response->json('token')];
        $this->getJson('/api/admin/me',$headers)->assertOk()->assertJsonPath('user.permissions',['payments.view']);
        foreach(['password','token_hash','remember_token','access_advanced_network_tools'] as $field)$this->assertArrayNotHasKey($field,$response->json('user'));
        $this->assertEqualsWithDelta(43200,now()->diffInSeconds(AdminApiToken::first()->expires_at),3);
        $this->postJson('/api/admin/logout',[],$headers)->assertOk();$this->getJson('/api/admin/me',$headers)->assertUnauthorized();
        $headers=$this->headers($user);$user->forceFill(['is_active'=>false])->save();
        $this->postJson('/api/auth/login',['email'=>$user->email,'password'=>'Strong-password123!'])->assertUnprocessable();
        $this->getJson('/api/admin/me',$headers)->assertUnauthorized();
        $user->forceFill(['is_active'=>true])->save();AdminApiToken::query()->update(['expires_at'=>now()->subMinute()]);
        $this->getJson('/api/admin/me',$headers)->assertUnauthorized();$this->getJson('/api/admin/me')->assertUnauthorized();
    }
    public function test_every_admin_route_has_policy_and_denies_unassigned_staff(): void
    {
        Http::preventStrayRequests();$headers=$this->headers($this->user());$count=0;
        $super=$this->user([],true);
        $superRequest=Request::create('/api/admin/me');$superRequest->setUserResolver(fn()=>$super);
        foreach(Route::getRoutes() as $route){
            if(!str_starts_with($route->uri(),'api/admin/'))continue;
            $middleware=$route->gatherMiddleware();$this->assertContains('admin.token',$middleware,$route->uri());
            if(in_array($route->uri(),['api/admin/me','api/admin/logout'],true))continue;
            $policies=array_values(array_filter($middleware,fn($item)=>str_starts_with($item,'permission:')));
            $this->assertNotEmpty($policies,$route->uri());
            if (!in_array($route->methods()[0], ['GET','HEAD'],true)) {
                $this->assertTrue((bool) array_filter($policies,fn($policy)=>str_ends_with($policy,'.manage') || in_array($policy,['permission:sessions.disconnect','permission:router.advanced'],true)), $route->uri());
            }
            foreach($policies as $policy){
                $key=substr($policy,11);$this->assertContains($key,[...AdminPermissions::KEYS,'super']);
                $allowed=(new RequireAdminPermission())->handle($superRequest,fn()=>response()->json(['allowed'=>true]),$key);
                $this->assertSame(200,$allowed->status(),$route->uri());
            }
            $url='/'.preg_replace('/\{[^}]+\}/','1',$route->uri());
            $this->json($route->methods()[0],$url,[],$headers)->assertForbidden();$count++;
        }
        $this->assertGreaterThan(45,$count);Http::assertNothingSent();
    }
    public function test_super_bypasses_all_permissions_without_pivot_rows(): void
    {
        $user=$this->user([],true);$this->assertSame(0,$user->permissions()->count());
        $request=Request::create('/api/admin/me');$request->setUserResolver(fn()=>$user);
        foreach([...AdminPermissions::KEYS,'super'] as $permission){
            $response=(new RequireAdminPermission())->handle($request,fn()=>response()->json(['allowed'=>true]),$permission);
            $this->assertSame(200,$response->status(),$permission);
        }
    }
    public function test_view_permissions_allow_reads_and_deny_writes_or_other_areas(): void
    {
        Http::preventStrayRequests();
        foreach([
            ['payments.view','payments','POST','router/sync'],['plans.view','plans','POST','plans'],
            ['sessions.view','sessions','POST','sessions/1/disconnect'],['router.view','router/naming','PUT','router/naming'],
            ['users.view','users','POST','users'],['vouchers.view','vouchers','POST','vouchers/generate'],
            ['support.view','support','PATCH','support/1'],['businesses.view','businesses','POST','businesses'],
        ] as [$permission,$read,$method,$write]){
            $headers=$this->headers($this->user([$permission]));
            $this->getJson('/api/admin/'.$read,$headers)->assertOk();$this->json($method,'/api/admin/'.$write,[],$headers)->assertForbidden();
        }
        Http::assertNothingSent();
    }
    public function test_router_advanced_is_the_only_source_of_truth(): void
    {
        $user=$this->user();$user->forceFill(['access_advanced_network_tools'=>true])->save();$headers=$this->headers($user);
        $this->getJson('/api/admin/router/advanced/permission',$headers)->assertForbidden();
        $user->permissions()->sync(Permission::where('key','router.advanced')->pluck('id'));$user->forceFill(['access_advanced_network_tools'=>false])->save();
        $this->getJson('/api/admin/router/advanced/permission',$headers)->assertOk()->assertJsonPath('allowed',true);
        $this->postJson('/api/admin/router/advanced/events',['action'=>'WINBOX_LAUNCH_REQUESTED','result'=>'manual','client_platform'=>'Linux'],$headers)->assertOk();
        $this->postJson('/api/admin/router/sync',[],$headers)->assertForbidden();
    }
    public function test_create_hashes_password_and_rejects_duplicates_invalid_permissions_and_weak_passwords(): void
    {
        $headers=$this->headers($this->user([],true));$response=$this->postJson('/api/admin/users',$this->payload(),$headers)->assertCreated()->assertJsonPath('user.is_super_user',false)->assertJsonPath('user.is_active',true);
        $staff=User::findOrFail($response->json('user.id'));$this->assertTrue(Hash::check($this->payload()['password'],$staff->password));$this->assertNotSame($this->payload()['password'],$staff->password);
        $this->postJson('/api/admin/users',$this->payload(),$headers)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/admin/users',array_replace($this->payload(),['email'=>'other@example.test','permissions'=>['invalid.key']]),$headers)->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $this->postJson('/api/admin/users',array_replace($this->payload(),['email'=>'other@example.test','password'=>'weak','password_confirmation'=>'weak']),$headers)->assertUnprocessable();
        $this->getJson('/api/admin/users/'.$staff->id,$headers)->assertOk()->assertJsonPath('user.permissions',['payments.view']);$this->assertArrayNotHasKey('password',$response->json('user'));
        $payload=$this->payload();unset($payload['permissions']);$payload['email']='unassigned@example.test';
        $this->postJson('/api/admin/users',$payload,$headers)->assertCreated()->assertJsonPath('user.permissions',[]);
        unset($payload['password_confirmation']);$payload['email']='unconfirmed@example.test';
        $this->postJson('/api/admin/users',$payload,$headers)->assertUnprocessable();
        $list=$this->getJson('/api/admin/users',$this->headers($this->user(['users.view'])))->assertOk();
        foreach($list->json('data') as $record)$this->assertSame(['id','name','email','is_active','is_super_user','permissions'],array_keys($record));
    }
    public function test_users_manage_never_allows_staff_to_elevate_or_change_users(): void
    {
        $staff=$this->user(['users.manage','users.view']);$headers=$this->headers($staff);$other=$this->user();
        $this->postJson('/api/admin/users',$this->payload(),$headers)->assertForbidden();
        foreach([$staff,$other] as $target){
            $this->putJson('/api/admin/users/'.$target->id,['is_super_user'=>true],$headers)->assertForbidden();
            $this->putJson('/api/admin/users/'.$target->id.'/permissions',['permissions'=>['router.manage']],$headers)->assertForbidden();
            $this->patchJson('/api/admin/users/'.$target->id.'/status',['is_active'=>false],$headers)->assertForbidden();
            $this->putJson('/api/admin/users/'.$target->id.'/password',$this->payload(),$headers)->assertForbidden();
        }
        $this->assertFalse($staff->fresh()->is_super_user);$this->assertTrue($staff->fresh()->is_active);
    }
    public function test_security_changes_revoke_tokens_and_permissions_can_be_removed(): void
    {
        $headers=$this->headers($this->user([],true));$staff=$this->user(['payments.view']);$old=$this->headers($staff);
        $this->putJson('/api/admin/users/'.$staff->id,['name'=>'Renamed'],$headers)->assertOk();
        $this->getJson('/api/admin/me',$old)->assertOk();
        $this->putJson('/api/admin/users/'.$staff->id.'/permissions',['permissions'=>[]],$headers)->assertOk()->assertJsonPath('user.permissions',[]);$this->getJson('/api/admin/me',$old)->assertUnauthorized();
        $old=$this->headers($staff);$this->putJson('/api/admin/users/'.$staff->id.'/password',$this->payload(),$headers)->assertOk();$this->getJson('/api/admin/me',$old)->assertUnauthorized();
        $old=$this->headers($staff);$this->patchJson('/api/admin/users/'.$staff->id.'/status',['is_active'=>false],$headers)->assertOk();$this->getJson('/api/admin/me',$old)->assertUnauthorized();
        $this->postJson('/api/auth/login',['email'=>$staff->email,'password'=>'Strong-password123!'])->assertUnprocessable();
        $this->patchJson('/api/admin/users/'.$staff->id.'/status',['is_active'=>true],$headers)->assertOk();$this->postJson('/api/auth/login',['email'=>$staff->email,'password'=>'Strong-password123!'])->assertOk();
    }
    public function test_last_active_super_is_protected_and_super_can_promote_and_demote(): void
    {
        $super=$this->user([],true);$headers=$this->headers($super);
        $this->patchJson('/api/admin/users/'.$super->id.'/status',['is_active'=>false],$headers)->assertUnprocessable();$this->putJson('/api/admin/users/'.$super->id,['is_super_user'=>false],$headers)->assertUnprocessable();
        $inactive=$this->user([],true);$inactive->forceFill(['is_active'=>false])->save();$this->putJson('/api/admin/users/'.$super->id,['is_super_user'=>false],$headers)->assertUnprocessable();
        $staff=$this->user();$old=$this->headers($staff);
        $this->putJson('/api/admin/users/'.$staff->id,['is_super_user'=>true],$headers)->assertOk()->assertJsonPath('user.is_super_user',true);$this->getJson('/api/admin/me',$old)->assertUnauthorized();
        $this->putJson('/api/admin/users/'.$staff->id,['is_super_user'=>false,'permissions'=>['payments.view']],$headers)->assertOk()->assertJsonPath('user.permissions',['payments.view']);
    }
    public function test_management_audit_context_contains_only_safe_identifiers(): void
    {
        $headers=$this->headers($this->user([],true));Log::spy();$this->postJson('/api/admin/users',$this->payload(),$headers)->assertCreated();
        Log::shouldHaveReceived('info')->with('Admin user management',\Mockery::on(fn($context)=>array_keys($context)===['actor_id','user_id','event','fields'] && $context['event']==='created'))->once();
    }
    public function test_storage_failure_never_exposes_or_logs_password_bindings(): void
    {
        $headers=$this->headers($this->user([],true));$staff=$this->user();
        Log::spy();
        \Illuminate\Support\Facades\DB::partialMock()->shouldReceive('transaction')->once()->andThrow(
            new \Illuminate\Database\QueryException('sqlite', 'update users set password = ?', ['never-log-this-hash'], new \RuntimeException('storage unavailable'))
        );
        $response=$this->putJson('/api/admin/users/'.$staff->id.'/password',$this->payload(),$headers)->assertStatus(503);
        $this->assertStringNotContainsString('never-log-this-hash',$response->getContent());
        Log::shouldHaveReceived('error')->with('Admin user storage failed',\Mockery::on(fn($context)=>array_keys($context)===['actor_id']))->once();
    }
}
