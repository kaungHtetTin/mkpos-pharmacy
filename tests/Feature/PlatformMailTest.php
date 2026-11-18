<?php

namespace Tests\Feature;

use App\Models\{User, PlatformMailSetting};
use App\Security\RecoveryMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;
class PlatformMailTest extends TestCase
{
    use RefreshDatabase;
    private User $office;
    protected function setUp(): void
    {
        parent::setUp();
        config(['operations.mfa_required' => false]);
        $this->office = User::factory()->create();
        $this->office->forceFill(['is_platform_admin' => true])->save();
    }
    private function settings(array $values = []): array
    {
        return array_replace(['enabled' => true, 'username' => 'sender@example.test', 'password' => 'synthetic-SMTP-password', 'port' => 465, 'from_name' => 'Pharmacy POS'], $values);
    }
    public function test_only_owner_office_role_can_configure_email(): void
    {
        $this->actingAs($this->office)->get('/platform/email')->assertOk();
        foreach (['support', 'billing', 'admin'] as $role) {
            $this->office->forceFill(['platform_role' => $role])->save();
            $this->actingAs($this->office)->get('/platform/email')->assertForbidden();
            $this->put('/platform/email', $this->settings())->assertForbidden();
        }
    }
    public function test_smtp_password_is_encrypted_hidden_and_not_audited(): void
    {
        $this->actingAs($this->office)->put('/platform/email', $this->settings())->assertRedirect()->assertSessionHasNoErrors();
        $s = PlatformMailSetting::find(1);
        $this->assertSame('synthetic-SMTP-password', $s->password);
        $this->assertStringNotContainsString('synthetic-SMTP-password', DB::table('platform_mail_settings')->value('password'));
        $this->assertArrayNotHasKey('password', $s->toArray());
        $response = $this->get('/platform/email', ['X-SPA' => 'true'])->assertJsonPath('props.mail_settings.has_password', true)->assertJsonMissingPath('props.mail_settings.password');
        $this->assertStringNotContainsString('synthetic-SMTP-password', $response->getContent());
        $this->assertStringNotContainsString('synthetic-SMTP-password', DB::table('platform_activity_logs')->value('properties'));
    }
    public function test_blank_password_preserves_secret_and_changed_mailbox_requires_new_secret(): void
    {
        $this->actingAs($this->office)->put('/platform/email', $this->settings())->assertRedirect();
        $this->put('/platform/email', $this->settings(['password' => '', 'from_name' => 'Changed sender']))->assertSessionHasNoErrors();
        $this->assertSame('synthetic-SMTP-password', PlatformMailSetting::find(1)->password);
        $this->put('/platform/email', $this->settings(['username' => 'other@example.test', 'password' => '']))->assertSessionHasErrors('password');
    }
    public function test_only_secure_hostinger_ports_are_allowed(): void
    {
        $this->actingAs($this->office)->put('/platform/email', $this->settings(['port' => 25]))->assertSessionHasErrors('port');
        $this->put('/platform/email', $this->settings(['port' => 587]))->assertSessionHasNoErrors();
        config(['mail.default' => 'smtp']);
        $this->assertSame('password-recovery', app(RecoveryMail::class)->configure());
        $this->assertSame('smtp.hostinger.com', config('mail.mailers.password-recovery.host'));
        $this->assertTrue(config('mail.mailers.password-recovery.require_tls'));
    }
    public function test_test_email_requires_enabled_sender_and_records_acceptance(): void
    {
        Mail::fake();
        $this->actingAs($this->office)->post('/platform/email/test', ['recipient' => 'owned-inbox@example.test'])->assertSessionHasErrors('recipient');
        $this->put('/platform/email', $this->settings())->assertSessionHasNoErrors();
        $this->post('/platform/email/test', ['recipient' => 'owned-inbox@example.test'])->assertSessionHasNoErrors();
        $this->assertSame('sent', PlatformMailSetting::find(1)->last_test_status);
    }
    public function test_mail_configuration_requires_mfa_in_production_mode(): void
    {
        config(['operations.mfa_required' => true]);
        $this->actingAs($this->office)->get('/platform/email')->assertRedirect(route('platform.security.index'));
        $this->put('/platform/email', $this->settings())->assertRedirect(route('platform.security.index'));
        $this->assertFalse(PlatformMailSetting::find(1)->enabled);
    }
}
