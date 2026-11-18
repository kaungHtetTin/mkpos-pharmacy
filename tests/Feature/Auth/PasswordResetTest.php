<?php

namespace Tests\Feature\Auth;

use App\Models\{User, BusinessSubscription};
use App\Notifications\AccountPasswordReset;
use App\Services\Businesses\ProvisionBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Notification, Password};
use Tests\TestCase;
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'recovery@example.test', 'operations.mfa_required' => false]);
    }
    private function owner(): User
    {
        $u = User::factory()->create(['email' => 'owner@example.test']);
        app(ProvisionBusiness::class)->create(['name' => 'Reset Pharmacy', 'slug' => 'reset-pharmacy'], $u);
        return $u;
    }
    private function resetData(User $u, string $token, array $values = []): array
    {
        return array_replace(['email' => $u->email, 'token' => $token, 'password' => 'new-secure-owner-password', 'password_confirmation' => 'new-secure-owner-password'], $values);
    }
    public function test_link_request_page_is_compact_and_available(): void
    {
        $this->get('/forgot-password')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/forgot-password', ['X-SPA' => 'true'])->assertJsonPath('props.mail_available', true);
    }
    public function test_known_unknown_and_throttled_emails_return_the_same_message(): void
    {
        Notification::fake();
        $u = $this->owner();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $u->email])->assertRedirect('/forgot-password');
        $first = session('status');
        $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertRedirect();
        $this->assertSame($first, session('status'));
        $this->post('/forgot-password', ['email' => $u->email])->assertRedirect();
        $this->assertSame($first, session('status'));
        Notification::assertSentToTimes($u, AccountPasswordReset::class, 1);
    }
    public function test_request_normalizes_email_and_uses_canonical_reset_url(): void
    {
        Notification::fake();
        $u = $this->owner();
        config(['app.url' => 'https://pharmacypos.example.test']);
        $this->post('/forgot-password', ['email' => ' OWNER@EXAMPLE.TEST '])->assertSessionHasNoErrors();
        Notification::assertSentTo($u, AccountPasswordReset::class, function ($n) use ($u) {
            $url = $n->toMail($u)->actionUrl;
            $this->assertStringStartsWith('https://pharmacypos.example.test/reset-password/', $url);
            $this->assertStringContainsString('email=owner%40example.test', $url);
            $this->assertTrue(Hash::check($n->token, DB::table('password_resets')->where('email', $u->email)->value('token')));
            return true;
        });
    }
    public function test_valid_link_renders_and_resets_password(): void
    {
        $u = $this->owner();
        $token = Password::broker()->createToken($u);
        $this->get('/reset-password/' . $token . '?email=' . urlencode($u->email), ['X-SPA' => 'true'])->assertJsonPath('props.link_valid', true)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/reset-password', $this->resetData($u, $token))->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-secure-owner-password', $u->fresh()->password));
        $this->assertDatabaseMissing('password_resets', ['email' => $u->email]);
        $this->assertSame(1, $u->fresh()->auth_epoch);
        $this->assertGuest();
    }
    public function test_used_reset_link_cannot_change_the_password_again(): void
    {
        $u = $this->owner();
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($u, $token))->assertRedirect();
        $this->post('/reset-password', $this->resetData($u, $token, ['password' => 'another-new-password', 'password_confirmation' => 'another-new-password']))->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('new-secure-owner-password', $u->fresh()->password));
    }
    public function test_expired_or_invalid_link_is_actionable_and_cannot_reset(): void
    {
        $u = $this->owner();
        $token = Password::broker()->createToken($u);
        $this->travel(61)->minutes();
        $this->get('/reset-password/' . $token . '?email=' . urlencode($u->email), ['X-SPA' => 'true'])->assertJsonPath('props.link_valid', false);
        $this->post('/reset-password', $this->resetData($u, $token))->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('password', $u->fresh()->password));
    }
    public function test_reset_token_is_bound_to_the_matching_identity(): void
    {
        $u = $this->owner();
        $other = User::factory()->create();
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($other, $token))->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('password', $other->fresh()->password));
        $this->assertNotNull(DB::table('password_resets')->where('email', $u->email)->first());
    }
    public function test_reset_revokes_tokens_and_old_web_sessions(): void
    {
        $u = $this->owner();
        $issued = $u->createToken('old-device');
        $issued->accessToken->forceFill(['business_id' => $u->assignedBusiness()->id])->save();
        $token = Password::broker()->createToken($u);
        $oldRemember = $u->remember_token;
        $this->post('/reset-password', $this->resetData($u, $token))->assertRedirect();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotSame($oldRemember, $u->fresh()->remember_token);
        $this->actingAs($u)->withSession(['auth_epoch' => 0, 'auth_user_id' => $u->id])->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }
    public function test_new_password_logs_in_but_old_password_does_not(): void
    {
        $u = $this->owner();
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($u, $token));
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $u->email, 'password' => 'new-secure-owner-password'])->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertOk();
    }
    public function test_office_mfa_and_business_subscription_are_not_reset(): void
    {
        $u = $this->owner();
        $subscription = BusinessSubscription::sole()->toArray();
        $u->forceFill(['office_mfa_secret' => 'TESTSECRETFORPRESERVATION'])->save();
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($u, $token));
        $this->assertSame('TESTSECRETFORPRESERVATION', $u->fresh()->office_mfa_secret);
        $this->assertSame($subscription, BusinessSubscription::sole()->toArray());
    }
    public function test_weak_mismatched_and_oversized_passwords_do_not_consume_link(): void
    {
        $u = $this->owner();
        $token = Password::broker()->createToken($u);
        foreach ([['short', 'short'], ['new-secure-owner-password', 'different'], [str_repeat('မြ', 30), str_repeat('မြ', 30)]] as [$password, $confirm]) {
            $this->post('/reset-password', $this->resetData($u, $token, ['password' => $password, 'password_confirmation' => $confirm]))->assertSessionHasErrors('password');
        }
        $this->assertTrue(Password::broker()->tokenExists($u, $token));
    }
    public function test_disabled_mail_does_not_pretend_to_send_a_reset_link(): void
    {
        config(['mail.default' => 'log']);
        Notification::fake();
        $u = $this->owner();
        $this->get('/forgot-password', ['X-SPA' => 'true'])->assertJsonPath('props.mail_available', false);
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHasErrors('email');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_resets', 0);
    }
    public function test_mail_failure_rolls_back_reset_token_and_keeps_response_generic(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('Synthetic SMTP failure'));
        $u = $this->owner();
        $this->post('/forgot-password', ['email' => $u->email])->assertRedirect()->assertSessionHas('status', \App\Http\Controllers\Auth\PasswordResetLinkController::MESSAGE);
        $this->assertDatabaseCount('password_resets', 0);
    }
    public function test_reset_endpoint_has_its_own_rate_limit(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/reset-password', [])->assertSessionHasErrors();
        }
        $this->post('/reset-password', [])->assertStatus(429);
    }
    public function test_reset_tokens_are_not_returned_in_success_payloads(): void
    {
        Notification::fake();
        $u = $this->owner();
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHasNoErrors();
        $props = $this->get('/forgot-password', ['X-SPA' => 'true'])->json('props');
        $this->assertArrayNotHasKey('token', $props);
        $this->assertDatabaseCount('password_resets', 1);
    }
    public function test_recovering_office_password_preserves_mfa_enrollment(): void
    {
        config(['operations.mfa_required' => true]);
        $u = User::factory()->create();
        $u->forceFill(['is_platform_admin' => true, 'office_mfa_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', 'office_recovery_codes' => [hash('sha256', 'example-recovery')]])->save();
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($u, $token))->assertRedirect(route('login'));
        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $u->fresh()->office_mfa_secret);
        $this->assertSame([hash('sha256', 'example-recovery')], $u->fresh()->office_recovery_codes);
        $this->post('/login', ['email' => $u->email, 'password' => 'new-secure-owner-password'])->assertRedirect(route('platform.dashboard'));
        $this->get('/platform/dashboard')->assertRedirect(route('platform.security.index'));
    }
    public function test_recovery_does_not_reactivate_inactive_business_access(): void
    {
        $u = $this->owner();
        $u->businessMembership()->update(['status' => 'Inactive']);
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($u, $token))->assertRedirect(route('login'));
        $this->assertDatabaseHas('business_memberships', ['user_id' => $u->id, 'status' => 'Inactive']);
        $this->post('/login', ['email' => $u->email, 'password' => 'new-secure-owner-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
