<?php

namespace Tests\Feature;

use App\Models\{Business, User, SupportTicket, SupportMessage, PlatformActivityLog, BusinessSubscription, Branch, Role, Setting};
use App\Services\Businesses\ProvisionBusiness;
use App\Operations\{BusinessExport, PlatformBackup, Totp};
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, File, Hash};
use Illuminate\Support\Str;
use Tests\TestCase;
class PlatformOperationsTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private User $other;
    private User $office;
    private Business $a;
    private Business $b;
    private string $backupRoot;
    protected function setUp(): void
    {
        parent::setUp();
        config(['operations.mfa_required' => false]);
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->office = User::factory()->create();
        $this->office->forceFill(['is_platform_admin' => true])->save();
        $this->a = app(ProvisionBusiness::class)->create(['name' => 'Operations Alpha', 'slug' => 'ops-alpha'], $this->owner);
        $this->b = app(ProvisionBusiness::class)->create(['name' => 'Operations Beta', 'slug' => 'ops-beta'], $this->other);
        $this->backupRoot = storage_path('app/testing/backups-' . Str::uuid());
        config(['operations.backup_path' => $this->backupRoot, 'operations.backup_password' => 'test-only-backup-password-with-32-chars']);
    }
    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupRoot);
        parent::tearDown();
    }
    private function ticket(): SupportTicket
    {
        $this->actingAs($this->owner)->post('/support', ['subject' => 'Stock display', 'body' => 'Please help with the stock display.'])->assertRedirect()->assertSessionHasNoErrors();
        return SupportTicket::firstOrFail();
    }
    private function grant(SupportTicket $t): void
    {
        $this->actingAs($this->office)->post('/platform/support/' . $t->id . '/action', ['action' => 'claim'])->assertRedirect();
        $this->post('/platform/support/' . $t->id . '/action', ['action' => 'request_access'])->assertRedirect();
        $this->actingAs($this->owner)->post('/support/' . $t->id . '/action', ['action' => 'grant'])->assertRedirect()->assertSessionHasNoErrors();
    }
    public function test_dashboard_separates_currencies_and_pharmacy_data(): void
    {
        $this->actingAs($this->office)->get('/platform/dashboard')->assertOk();
        $p = $this->get('/platform/dashboard', ['X-SPA' => 'true'])->json('props');
        $this->assertSame(2, $p['totals']['businesses']);
        $this->assertSame(2, $p['states']['trialing']);
        $this->assertSame([], $p['collections']);
        $this->assertCount(2, $p['businesses']['data']);
        $this->actingAs($this->owner)->get('/platform/dashboard')->assertForbidden();
    }
    public function test_office_roles_are_enforced_server_side(): void
    {
        $this->actingAs($this->office)->delete('/profile', ['password' => 'password'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $this->office->id]);
        $this->office->forceFill(['platform_role' => 'support'])->save();
        $this->actingAs($this->office)->get('/platform/dashboard', ['X-SPA' => 'true'])->assertOk()->assertJsonPath('props.billing_visible', false);
        foreach (['/platform/billing', '/platform/plans', '/platform/businesses', '/platform/audit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/platform/support')->assertOk();
        $this->office->forceFill(['platform_role' => 'billing'])->save();
        $this->get('/platform/plans')->assertOk();
        $this->get('/platform/support')->assertForbidden();
        $this->office->forceFill(['platform_role' => 'admin'])->save();
        $this->get('/platform/businesses')->assertOk();
        $this->post('/platform/billing/invoices', [])->assertForbidden();
    }
    public function test_business_lists_and_search_are_paginated(): void
    {
        for ($i = 0; $i < 23; $i++) {
            Business::create(['name' => 'Extra ' . $i, 'slug' => 'extra-' . $i, 'status' => 'Active']);
        }
        $this->actingAs($this->office)->get('/platform/businesses', ['X-SPA' => 'true'])->assertJsonPath('props.businesses.total', 25)->assertJsonCount(20, 'props.businesses.data');
        $this->get('/platform/dashboard?q=Operations%20Alpha', ['X-SPA' => 'true'])->assertJsonPath('props.businesses.total', 1);
    }
    public function test_support_is_owner_only_and_isolated(): void
    {
        $t = $this->ticket();
        $this->actingAs($this->other)->get('/support/' . $t->id)->assertNotFound();
        $this->post('/support/' . $t->id . '/reply', ['body' => 'Cross tenant'])->assertNotFound();
        $this->actingAs($this->owner)->get('/support', ['X-SPA' => 'true'])->assertJsonPath('props.tickets.total', 1);
        $cashier = app(BusinessContext::class)->run($this->a, function () {
            $u = User::factory()->create(['branch_id' => Branch::firstOrFail()->id]);
            $u->roles()->attach(Role::where('name', 'Cashier')->firstOrFail()->id);
            return $u;
        });
        $this->actingAs($cashier)->get('/support')->assertForbidden();
        $this->get('/business-export')->assertForbidden();
    }
    public function test_diagnostics_require_approval_and_expire(): void
    {
        $t = $this->ticket();
        $this->actingAs($this->office)->get('/platform/support/' . $t->id . '/diagnostics')->assertForbidden();
        $this->grant($t);
        $this->actingAs($this->office)->get('/platform/support/' . $t->id . '/diagnostics')->assertOk()->assertJsonPath('business_id', $this->a->id)->assertJsonPath('counts.branches', 1)->assertJsonMissingPath('customers');
        $this->assertDatabaseHas('platform_activity_logs', ['action' => 'support.diagnostics_read', 'business_id' => $this->a->id]);
        $this->travel(31)->minutes();
        $this->get('/platform/support/' . $t->id . '/diagnostics')->assertForbidden();
    }
    public function test_revoke_and_close_end_access_immediately(): void
    {
        $t = $this->ticket();
        $this->grant($t);
        $this->actingAs($this->owner)->post('/support/' . $t->id . '/action', ['action' => 'revoke'])->assertRedirect();
        $this->actingAs($this->office)->get('/platform/support/' . $t->id . '/diagnostics')->assertForbidden();
        $this->grant($t);
        $this->actingAs($this->owner)->post('/support/' . $t->id . '/action', ['action' => 'close'])->assertRedirect();
        $this->actingAs($this->office)->get('/platform/support/' . $t->id . '/diagnostics')->assertForbidden();
        $this->post('/platform/support/' . $t->id . '/reply', ['body' => 'Closed response'])->assertStatus(409);
        $this->post('/platform/support/' . $t->id . '/action', ['action' => 'reopen'])->assertRedirect();
        $this->get('/platform/support/' . $t->id . '/diagnostics')->assertForbidden();
    }
    public function test_office_cannot_grant_itself_access_or_export(): void
    {
        $t = $this->ticket();
        $this->actingAs($this->office)->post('/platform/support/' . $t->id . '/action', ['action' => 'grant'])->assertForbidden();
        $this->post('/business-export', ['confirmed' => true])->assertRedirect(route('platform.dashboard'));
        $this->actingAs($this->owner)->post('/support/' . $t->id . '/action', ['action' => 'claim'])->assertForbidden();
        $this->post('/support/' . $t->id . '/action', ['action' => 'grant'])->assertSessionHasErrors('support');
    }
    public function test_reassigning_support_revokes_prior_grant(): void
    {
        $t = $this->ticket();
        $this->grant($t);
        $second = User::factory()->create();
        $second->forceFill(['is_platform_admin' => true, 'platform_role' => 'support'])->save();
        $this->actingAs($second)->post('/platform/support/' . $t->id . '/action', ['action' => 'claim'])->assertRedirect();
        $this->get('/platform/support/' . $t->id . '/diagnostics')->assertForbidden();
        $this->actingAs($this->office)->get('/platform/support/' . $t->id . '/diagnostics')->assertForbidden();
    }
    public function test_expired_owner_can_contact_support_and_export(): void
    {
        BusinessSubscription::where('business_id', $this->a->id)->update(['trial_ends_at' => now()->subMinute()]);
        $this->actingAs($this->owner)->get('/products')->assertRedirect(route('billing.index'));
        $this->get('/support')->assertOk();
        $this->get('/business-export')->assertOk();
        $this->post('/support', ['subject' => 'Renewal help', 'body' => 'I need help renewing.'])->assertRedirect()->assertSessionHasNoErrors();
    }
    public function test_export_has_only_own_records_and_no_authentication_secrets(): void
    {
        app(BusinessContext::class)->run($this->a, function () {
            Setting::set('invoice.pharmacy_name', 'ALPHA EXPORTED');
            Setting::set('app.secret_token', 'NEVER-EXPORT-THIS');
        });
        $path = app(BusinessExport::class)->create($this->a);
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($path));
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $this->assertSame($this->a->id, $manifest['business']['id']);
            $all = '';
            foreach ($manifest['files'] as $name => $meta) {
                $raw = $zip->getFromName($name);
                $this->assertSame($meta['sha256'], hash('sha256', $raw));
                foreach (explode("\n", trim($raw)) as $line) {
                    if ($line !== '') {
                        $this->assertSame($this->a->id, json_decode($line, true, 512, JSON_THROW_ON_ERROR)['business_id']);
                    }
                }
                $all .= $raw;
            }
            $accounts = $zip->getFromName('data/accounts.jsonl');
            $this->assertStringContainsString($this->owner->email, $accounts);
            $this->assertStringNotContainsString($this->other->email, $accounts);
            $this->assertStringNotContainsString('password', $accounts);
            $this->assertStringNotContainsString($this->b->id, $all);
            $this->assertStringNotContainsString('NEVER-EXPORT-THIS', $all);
            $this->assertStringContainsString('ALPHA EXPORTED', $all);
            $zip->close();
        } finally {
            unlink($path);
        }
    }
    public function test_export_cap_failure_cleans_up_and_confirmation_is_required(): void
    {
        config(['operations.export_max_rows' => 1]);
        $before = glob(storage_path('app/private/exports/*'));
        try {
            app(BusinessExport::class)->create($this->a);
            $this->fail('Expected size limit.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('size limit', $e->getMessage());
        }
        $this->assertSame($before, glob(storage_path('app/private/exports/*')));
        $this->actingAs($this->owner)->post('/business-export')->assertSessionHasErrors('confirmed');
    }
    public function test_totp_matches_rfc_vector(): void
    {
        $this->assertSame('287082', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
    }
    public function test_mfa_enrollment_challenge_replay_and_recovery(): void
    {
        config(['operations.mfa_required' => true]);
        $this->actingAs($this->office)->get('/platform/dashboard')->assertRedirect(route('platform.security.index'));
        $this->post('/platform/security/setup', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/platform/security/setup', ['password' => 'password'])->assertRedirect();
        $secret = session('office_pending_secret');
        $this->assertNotNull($secret);
        $code = Totp::code($secret, intdiv(now()->timestamp, 30));
        $this->post('/platform/security/enable', ['code' => $code])->assertRedirect()->assertSessionHasNoErrors();
        $recovery = session('office_new_recovery');
        $this->assertCount(8, $recovery);
        $this->assertStringNotContainsString($secret, DB::table('users')->where('id', $this->office->id)->value('office_mfa_secret'));
        $this->actingAs($this->office->refresh())->get('/platform/dashboard')->assertOk();
        $this->get('/platform/security', ['X-SPA' => 'true'])->assertJsonMissingPath('props.auth.user.office_mfa_secret')->assertJsonMissingPath('props.auth.user.office_recovery_codes');
        $this->withSession(['office_mfa_at' => 0])->get('/platform/dashboard')->assertRedirect(route('platform.security.index'));
        $this->post('/platform/security/challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->post('/platform/security/challenge', ['code' => $recovery[0]])->assertRedirect(route('platform.dashboard'))->assertSessionHasNoErrors();
        $this->travel(61)->seconds();
        $this->post('/platform/security/challenge', ['code' => $recovery[0]])->assertSessionHasErrors('code');
    }
    public function test_mfa_throttle_and_tenant_rejection(): void
    {
        $this->actingAs($this->owner)->get('/platform/security')->assertForbidden();
        $this->actingAs($this->office);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/platform/security/challenge', ['code' => '000000'])->assertStatus(409);
        }
        $this->post('/platform/security/challenge', ['code' => '000000'])->assertStatus(429);
    }
    public function test_authenticated_responses_have_hardening_headers(): void
    {
        $this->get('/cashier')->assertOk()->assertSee('cashier-sw.js?v=',false);
        $this->actingAs($this->owner)->get('/support')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
        $this->assertStringContainsString('no-store', $this->get('/support')->headers->get('Cache-Control'));
    }
    public function test_backup_requires_private_path_and_strong_key(): void
    {
        config(['operations.backup_password' => null]);
        $this->artisan('platform:backup')->assertFailed();
        config(['operations.backup_password' => str_repeat('x', 32), 'operations.backup_path' => public_path('unsafe-backups')]);
        try {
            app(PlatformBackup::class)->create();
            $this->fail('Public path accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('outside', $e->getMessage());
        } finally {
            File::deleteDirectory(public_path('unsafe-backups'));
        }
    }
    public function test_backup_is_encrypted_verified_and_detects_tampering(): void
    {
        $backup = app(PlatformBackup::class);
        $file = $backup->create();
        $result = $backup->verify($file);
        $this->assertGreaterThanOrEqual(1, $result['files']);
        $this->assertFalse($backup->status()['stale']);
        $zip = new \ZipArchive();
        $zip->open($file);
        $this->assertFalse($zip->getFromName('database.sql'));
        $zip->setPassword(config('operations.backup_password'));
        $this->assertStringContainsString('CREATE TABLE', $zip->getFromName('database.sql'));
        $zip->addFromString('database.sql', 'tampered');
        $zip->setEncryptionName('database.sql', \ZipArchive::EM_AES_256);
        $zip->close();
        try {
            $backup->verify($file);
            $this->fail('Tampered backup passed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
    }
    public function test_mfa_session_expires_and_a_new_code_is_accepted(): void
    {
        config(['operations.mfa_required' => true]);
        $this->actingAs($this->office)->get('/profile')->assertRedirect(route('platform.security.index'));
        $this->put('/password', ['current_password' => 'password', 'password' => 'changed-password-example', 'password_confirmation' => 'changed-password-example'])->assertRedirect(route('platform.security.index'));
        $this->assertTrue(Hash::check('password', $this->office->fresh()->password));
        $secret = Totp::secret();
        $this->office->forceFill(['office_mfa_secret' => $secret])->save();
        $this->actingAs($this->office)->post('/platform/security/challenge', ['code' => Totp::code($secret, intdiv(now()->timestamp, 30))])->assertRedirect(route('platform.dashboard'));
        $this->actingAs($this->office->refresh())->get('/platform/dashboard')->assertOk();
        $this->travel(9)->hours();
        $this->get('/platform/dashboard')->assertRedirect(route('platform.security.index'));
        $this->post('/platform/security/challenge', ['code' => Totp::code($secret, intdiv(now()->timestamp, 30))])->assertRedirect(route('platform.dashboard'));
        $this->get('/platform/dashboard')->assertOk();
    }
    public function test_dashboard_keeps_verified_collections_in_separate_currencies(): void
    {
        $service = app(\App\Billing\SubscriptionService::class);
        foreach ([[$this->a, $this->owner, 'USD', 1999], [$this->b, $this->other, 'MMK', 50000]] as [$business, $owner, $currency, $amount]) {
            $plan = \App\Models\SubscriptionPlan::create(['code' => 'currency-' . strtolower($currency), 'name' => 'Currency test', 'amount_minor' => $amount, 'currency' => $currency, 'interval_months' => 1, 'grace_days' => 0, 'features' => ['cashier' => true, 'offline_sync' => true, 'reports' => true], 'is_active' => true, 'is_public' => true, 'is_trial' => false]);
            $invoice = $service->invoice($business, $plan, (string) Str::uuid(), $owner);
            $payment = $service->submit($invoice, $owner, ['request_key' => (string) Str::uuid(), 'method' => 'bank_transfer', 'reference' => 'reference-' . $currency, 'payment_date' => today()->toDateString()]);
            $service->review($payment, $this->office, true, null);
        }
        $p = $this->actingAs($this->office)->get('/platform/dashboard', ['X-SPA' => 'true'])->assertOk()->json('props');
        $totals = collect($p['collections'])->keyBy('currency');
        $this->assertSame(1999, (int) $totals['USD']['amount_minor']);
        $this->assertSame(50000, (int) $totals['MMK']['amount_minor']);
        $this->assertCount(2, $p['monthly']);
    }
    public function test_support_activity_does_not_consume_the_export_rate_limit(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->owner);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/support/' . $ticket->id . '/reply', ['body' => 'Update ' . $i])->assertRedirect();
        }
        $response = $this->post('/business-export', ['confirmed' => true])->assertOk()->assertDownload();
        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($file);
        unlink($file);
    }
}
