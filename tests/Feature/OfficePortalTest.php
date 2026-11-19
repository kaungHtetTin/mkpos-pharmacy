<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash, Mail, Password};
use Tests\TestCase;

class OfficePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['operations.office_only' => true, 'operations.mfa_required' => true]);
    }

    public function test_office_entry_has_office_branding_and_landing(): void
    {
        $this->get('/')->assertRedirect(route('platform.dashboard'));
        $this->get('/login', ['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.office_portal', true);
        $this->get('/platform/dashboard')->assertRedirect(route('login'));
    }

    public function test_business_signup_cashier_and_tenant_endpoints_are_unavailable(): void
    {
        foreach (['/register','/cashier/','/products','/billing','/support','/api/health'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post('/register', [])->assertNotFound();
        $this->post('/api/login', [])->assertNotFound();
    }

    public function test_tenant_credentials_cannot_sign_in_at_office(): void
    {
        $u = User::factory()->create();
        $this->post('/login', ['email'=>$u->email,'password'=>'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_office_credentials_still_require_mfa_and_keep_enrollment(): void
    {
        app(\App\Tenancy\BusinessContext::class)->set(null);
        $u = User::factory()->create(['is_platform_admin'=>true]);
        $secret = $u->office_mfa_secret;
        $this->post('/login', ['email'=>$u->email,'password'=>'password'])->assertRedirect(route('platform.dashboard'));
        $this->assertAuthenticatedAs($u);
        $this->get('/platform/dashboard')->assertRedirect(route('platform.security.index'));
        $this->get('/platform/email')->assertRedirect(route('platform.security.index'));
        $this->get('/profile')->assertRedirect(route('platform.security.index'));
        $this->assertSame($secret, $u->fresh()->office_mfa_secret);
    }

    public function test_existing_tenant_session_cannot_enter_office_profile(): void
    {
        $this->actingAs(User::factory()->create())->get('/profile')->assertForbidden();
    }

    public function test_office_recovery_does_not_issue_or_accept_tenant_reset_tokens(): void
    {
        config(['mail.default'=>'array']);
        Mail::mailer('array');
        $u = User::factory()->create();
        $token = Password::createToken($u);
        $this->get('/reset-password/'.$token.'?email='.urlencode($u->email), ['X-SPA'=>'true'])->assertJsonPath('props.link_valid', false);
        $this->post('/forgot-password', ['email'=>$u->email])->assertSessionHas('status');
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->post('/reset-password', ['email'=>$u->email,'token'=>$token,'password'=>'a-long-new-password','password_confirmation'=>'a-long-new-password'])->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('password', $u->fresh()->password));
        $this->assertTrue(Password::tokenExists($u,$token));
    }

    public function test_password_only_office_access_has_no_security_setup_or_challenge(): void
    {
        config(['operations.mfa_required' => false]);
        app(\App\Tenancy\BusinessContext::class)->set(null);
        $u = User::factory()->create(['is_platform_admin'=>true]);
        $this->post('/login', ['email'=>$u->email,'password'=>'password'])->assertRedirect(route('platform.dashboard'));
        foreach (['/platform/dashboard','/platform/businesses','/platform/plans','/platform/billing','/platform/support','/platform/audit','/platform/email','/profile'] as $path) {
            $this->get($path, ['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.office_mfa_required', false);
        }
        $this->withSession(['office_pending_secret'=>'unused-setup'])->get('/platform/security')->assertRedirect(route('platform.dashboard'))->assertSessionMissing('office_pending_secret');
        foreach (['setup','enable','challenge'] as $action) {
            $this->post('/platform/security/'.$action, [])->assertNotFound();
        }
        $this->assertNull($u->fresh()->office_mfa_secret);
    }

    public function test_regular_business_entry_is_unchanged(): void
    {
        config(['operations.office_only'=>false]);
        $this->get('/register')->assertOk();
        $this->get('/login', ['X-SPA'=>'true'])->assertJsonPath('props.office_portal', false);
        $u = User::factory()->create();
        $this->post('/login', ['email'=>$u->email,'password'=>'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u);
    }
}
