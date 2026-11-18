<?php

namespace Tests\Feature\Auth;

use App\Models\{Business,Branch,BusinessSubscription,Role,Setting,User};
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Event,Hash};
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $values=[]): array
    {
        return array_replace(['business_name'=>'New Pharmacy','name'=>'New Owner','email'=>'owner@example.test','password'=>'long-owner-password','password_confirmation'=>'long-owner-password','currency_symbol'=>'Ks'],$values);
    }

    public function test_registration_screen_is_public_and_reports_server_trial_terms(): void
    {
        $this->get('/register')->assertOk();
        $this->get('/register',['X-SPA'=>'true'])->assertJsonPath('component','Auth/Register')->assertJsonPath('props.trial_days',config('billing.trial_days'));
    }

    public function test_owner_creates_a_complete_business_and_signs_in_directly(): void
    {
        $this->post('/register',$this->payload())->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
        $user=User::sole();$business=Business::sole();$this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_platform_admin);$this->assertTrue(Hash::check('long-owner-password',$user->password));
        $this->assertSame($business->id,$user->assignedBusiness()->id);
        $this->assertDatabaseCount('business_memberships',1);$this->assertDatabaseCount('business_subscriptions',1);
        app(BusinessContext::class)->run($business,function()use($user){
            $this->assertTrue($user->hasRole('Owner'));$this->assertSame(1,Branch::count());$this->assertSame('Ks',Setting::get('app.currency_symbol'));$this->assertSame('New Pharmacy',Setting::get('invoice.pharmacy_name'));$this->assertNotNull($user->fresh()->currentBranchId());
        });
        $subscription=BusinessSubscription::sole();$this->assertEqualsWithDelta(now()->addDays(config('billing.trial_days'))->timestamp,$subscription->trial_ends_at->timestamp,5);
        $this->assertDatabaseHas('platform_activity_logs',['action'=>'business.self_registered','business_id'=>$business->id,'actor_id'=>$user->id]);
        $this->get('/dashboard')->assertOk();$this->get('/platform/dashboard')->assertForbidden();
    }

    public function test_signups_with_the_same_business_name_have_separate_workspaces(): void
    {
        $this->post('/register',$this->payload())->assertRedirect();$first=Business::sole();$this->post('/logout');
        $this->post('/register',$this->payload(['email'=>'second@example.test']))->assertRedirect()->assertSessionHasNoErrors();
        $second=Business::where('id','!=',$first->id)->sole();$this->assertNotSame($first->slug,$second->slug);
        $this->get('/business-members',['X-SPA'=>'true'])->assertOk();$this->assertSame($second->id,auth()->user()->assignedBusiness()->id);
        $this->assertDatabaseCount('businesses',2);$this->assertDatabaseCount('business_memberships',2);
    }

    public function test_email_is_normalized_and_cannot_be_reused_for_another_business(): void
    {
        $this->post('/register',$this->payload(['email'=>' Owner@Example.Test ','business_name'=>'  New Pharmacy  ']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users',['email'=>'owner@example.test']);$this->assertDatabaseHas('businesses',['name'=>'New Pharmacy']);$this->post('/logout');
        $this->post('/register',$this->payload(['email'=>'OWNER@EXAMPLE.TEST']))->assertSessionHasErrors('email');$this->assertGuest();$this->assertDatabaseCount('businesses',1);$this->assertDatabaseCount('users',1);
    }

    public function test_office_email_cannot_be_reused_to_create_a_business(): void
    {
        $office=User::factory()->create(['email'=>'office@example.test']);$office->forceFill(['is_platform_admin'=>true])->save();
        $this->post('/register',$this->payload(['email'=>$office->email]))->assertSessionHasErrors('email');$this->assertDatabaseCount('businesses',0);$this->assertDatabaseCount('business_memberships',0);
    }

    public function test_clients_cannot_choose_privileges_business_or_subscription_terms(): void
    {
        $this->post('/register',$this->payload(['is_platform_admin'=>true,'platform_role'=>'owner','business_id'=>'11111111-1111-4111-8111-111111111111','roles'=>['Owner'],'plan_id'=>'11111111-1111-4111-8111-111111111111','status'=>'Active','trial_days'=>999,'branch_id'=>'11111111-1111-4111-8111-111111111111']))->assertSessionHasErrors(['is_platform_admin','platform_role','business_id','roles','plan_id','status','trial_days','branch_id']);
        $this->assertGuest();$this->assertDatabaseCount('users',0);$this->assertDatabaseCount('businesses',0);
    }

    public function test_invalid_data_creates_no_partial_records(): void
    {
        $this->post('/register',$this->payload(['business_name'=>' ','email'=>'invalid','password'=>'short','password_confirmation'=>'different','currency_symbol'=>'invalid']))->assertSessionHasErrors(['business_name','email','password','currency_symbol']);
        $this->assertDatabaseCount('users',0);$this->assertDatabaseCount('businesses',0);$this->assertDatabaseCount('business_subscriptions',0);
    }

    public function test_passwords_over_bcrypt_byte_limit_are_rejected_cleanly(): void
    {
        $password=str_repeat('မြ',25);$this->post('/register',$this->payload(['password'=>$password,'password_confirmation'=>$password]))->assertSessionHasErrors('password');$this->assertDatabaseCount('users',0);
    }

    public function test_failed_provisioning_rolls_back_owner_business_and_trial(): void
    {
        Event::listen('eloquent.created: '.Branch::class,function(){throw new \RuntimeException('Synthetic provisioning failure');});
        $this->post('/register',$this->payload())->assertStatus(500);$this->assertGuest();
        foreach(['users','businesses','business_subscriptions','business_memberships','branches','roles'] as $table)$this->assertDatabaseCount($table,0);
    }

    public function test_authenticated_owner_cannot_register_another_business(): void
    {
        $this->post('/register',$this->payload())->assertRedirect();$this->post('/register',$this->payload(['email'=>'second@example.test']))->assertRedirect();$this->assertDatabaseCount('businesses',1);$this->assertDatabaseCount('users',1);
    }

    public function test_signup_rate_limit_rejects_excess_attempts_without_creating_accounts(): void
    {
        for($i=0;$i<5;$i++)$this->post('/register',[])->assertSessionHasErrors();
        $this->post('/register',$this->payload())->assertStatus(429);$this->assertDatabaseCount('users',0);$this->assertDatabaseCount('businesses',0);
    }

    public function test_new_owner_can_use_cashier_api_without_selecting_a_business(): void
    {
        $this->post('/register',$this->payload())->assertRedirect();$business=Business::sole();$this->post('/logout');
        $response=$this->postJson('/api/login',['email'=>'owner@example.test','password'=>'long-owner-password','device_name'=>'Signup QA'])->assertOk()->assertJsonPath('business.id',$business->id);
        $token=$response->json('token');$this->withToken($token)->getJson('/api/account/profile')->assertOk()->assertJsonPath('business_id',$business->id)->assertJsonPath('subscription.state','trialing');
    }
}
