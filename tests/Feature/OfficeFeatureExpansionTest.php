<?php

namespace Tests\Feature;

use App\Billing\{SubscriptionAccess, SubscriptionService};
use App\Models\{Business, BusinessSubscription, PlatformPaymentMethod, PlatformActivityLog, SubscriptionPlan, SubscriptionInvoice, SubscriptionPayment, User};
use App\Services\Businesses\ProvisionBusiness;
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Tests\TestCase;
class OfficeFeatureExpansionTest extends TestCase
{
    use RefreshDatabase;
    private User $office;
    private User $owner;
    private User $other;
    private Business $a;
    private Business $b;
    protected function setUp(): void
    {
        parent::setUp();
        config(['operations.mfa_required' => false]);
        $this->office = User::factory()->create();
        $this->office->forceFill(['is_platform_admin' => true])->save();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->a = app(ProvisionBusiness::class)->create(['name' => 'Feature Alpha', 'slug' => 'feature-alpha'], $this->owner);
        $this->b = app(ProvisionBusiness::class)->create(['name' => 'Feature Beta', 'slug' => 'feature-beta'], $this->other);
    }
    private function spaGet(string $path)
    {
        return $this->get($path, ['X-SPA' => 'true']);
    }
    private function method(array $d = []): array
    {
        return array_replace(['name' => 'Example Bank', 'type' => 'bank_transfer', 'account_name' => 'Platform Office', 'account_number' => 'QA-123', 'instructions' => 'Include invoice number.', 'is_active' => true, 'sort_order' => 1], $d);
    }
    private function paid(string $currency = 'USD', bool $approve = true): SubscriptionPayment
    {
        $p = SubscriptionPlan::create(['name' => $currency . ' plan', 'code' => 'p-' . Str::lower(Str::random(8)), 'currency' => $currency, 'amount_minor' => 2500, 'interval_months' => 1, 'grace_days' => 3, 'features' => ['cashier' => true, 'offline_sync' => true, 'reports' => true], 'is_active' => true, 'is_public' => true, 'is_trial' => false]);
        $business = \App\Models\SubscriptionPeriod::where('business_id', $this->a->id)->exists() ? $this->b : $this->a;
        if (\App\Models\SubscriptionPeriod::where('business_id', $business->id)->exists()) {
            $newOwner = User::factory()->create();
            $business = app(ProvisionBusiness::class)->create(['name' => 'Extra reports business', 'slug' => 'extra-' . Str::lower(Str::random(8))], $newOwner);
        }
        $owner = User::withoutGlobalScopes()->findOrFail($business->memberships()->value('user_id'));
        $service = app(SubscriptionService::class);
        $i = $service->invoice($business, $p, (string) Str::uuid(), $this->office);
        $payment = $service->submit($i, $owner, ['request_key' => (string) Str::uuid(), 'method' => 'bank_transfer', 'reference' => 'QA-' . Str::random(12), 'payment_date' => today()->toDateString()]);
        if ($approve) {
            $service->review($payment, $this->office, true, null);
        }
        return $payment->fresh();
    }
    public function test_new_sections_enforce_each_office_role_and_tenant_denial(): void
    {
        $areas = ['/platform/users', '/platform/admins', '/platform/calendar', '/platform/reports', '/platform/payment-methods', '/platform/health'];
        $this->actingAs($this->owner);
        foreach ($areas as $url) {
            $this->get($url)->assertForbidden();
        }
        $matrix = ['owner' => [true, true, true, true, true, true], 'admin' => [true, false, false, false, false, true], 'billing' => [false, false, true, true, true, false], 'support' => [false, false, false, false, false, false]];
        foreach ($matrix as $role => $rights) {
            $this->office->forceFill(['platform_role' => $role])->save();
            $this->actingAs($this->office);
            foreach ($areas as $i => $url) {
                $this->get($url)->assertStatus($rights[$i] ? 200 : 403);
            }
        }
    }
    public function test_business_detail_scopes_accounts_branches_activity_and_billing(): void
    {
        $this->actingAs($this->office);
        $p = $this->spaGet('/platform/businesses/' . $this->a->id)->assertOk()->json('props');
        $this->assertSame($this->a->id, $p['record']['id']);
        $this->assertCount(1, $p['members']);
        $this->assertSame($this->owner->email, $p['members'][0]['email']);
        $this->assertContains('Owner', $p['members'][0]['roles']);
        $this->assertCount(1, $p['branches']);
        $this->assertStringNotContainsString($this->other->email, json_encode($p));
        $this->assertArrayNotHasKey('password', $p['members'][0]);
        $this->paid();
        $this->office->forceFill(['platform_role' => 'admin'])->save();
        $this->actingAs($this->office);
        $this->spaGet('/platform/businesses/' . $this->a->id)->assertJsonPath('props.billing_visible', false)->assertJsonCount(0, 'props.invoices')->assertJsonCount(0, 'props.payments');
    }
    public function test_business_profile_update_is_audited_and_duplicate_slug_is_rejected(): void
    {
        $this->actingAs($this->office)->put('/platform/businesses/' . $this->a->id . '/details', ['name' => 'Edited Alpha', 'slug' => 'edited-alpha', 'status' => 'Active'])->assertSessionHasNoErrors();
        $this->assertSame('Edited Alpha', $this->a->fresh()->name);
        $this->assertSame('Feature Beta', $this->b->fresh()->name);
        $this->assertDatabaseHas('platform_activity_logs', ['business_id' => $this->a->id, 'action' => 'business.profile_updated']);
        $this->put('/platform/businesses/' . $this->a->id . '/details', ['name' => 'Duplicate', 'slug' => $this->b->slug, 'status' => 'Active'])->assertSessionHasErrors('slug');
    }
    public function test_trial_extension_changes_only_trial_end_and_records_reason(): void
    {
        $s = BusinessSubscription::where('business_id', $this->a->id)->firstOrFail();
        $before = $s->trial_ends_at->copy();
        $this->actingAs($this->office)->post('/platform/businesses/' . $this->a->id . '/trial', ['days' => '7', 'note' => 'Onboarding requested'])->assertSessionHasNoErrors();
        $this->assertTrue($s->fresh()->trial_ends_at->equalTo($before->addDays(7)));
        $this->assertSame('Active', $this->a->fresh()->status);
        $this->assertDatabaseHas('platform_activity_logs', ['action' => 'subscription.trial_extended']);
        $this->post('/platform/businesses/' . $this->a->id . '/trial', ['days' => 365, 'note' => 'Too far'])->assertSessionHasErrors('days');
    }
    public function test_paid_business_trial_cannot_be_extended(): void
    {
        $this->paid();
        $s = BusinessSubscription::where('business_id', $this->a->id)->firstOrFail();
        $old = $s->trial_ends_at;
        $this->actingAs($this->office)->post('/platform/businesses/' . $this->a->id . '/trial', ['days' => 2, 'note' => 'Not permitted'])->assertSessionHasErrors('days');
        $this->assertTrue($s->fresh()->trial_ends_at->equalTo($old));
    }
    public function test_account_search_business_filter_and_private_fields(): void
    {
        $this->actingAs($this->office);
        $this->spaGet('/platform/users?business_id=' . $this->a->id)->assertJsonCount(1, 'props.accounts.data')->assertJsonPath('props.accounts.data.0.email', $this->owner->email)->assertJsonMissingPath('props.accounts.data.0.password');
        $this->spaGet('/platform/users?q=' . urlencode($this->other->email))->assertJsonCount(1, 'props.accounts.data')->assertJsonPath('props.accounts.data.0.business_id', $this->b->id);
    }
    public function test_account_updates_revoke_sessions_and_cannot_promote_or_move_tenant(): void
    {
        $token = app(BusinessContext::class)->run($this->a, fn() => $this->owner->createToken('test'));
        $epoch = $this->owner->auth_epoch;
        $this->actingAs($this->office)->put('/platform/users/' . $this->owner->id, ['name' => 'Updated Owner', 'email' => 'new-owner@example.test', 'status' => 'Inactive', 'is_platform_admin' => true, 'business_id' => $this->b->id])->assertSessionHasNoErrors();
        $u = $this->owner->fresh();
        $this->assertFalse($u->is_platform_admin);
        $this->assertSame($epoch + 1, $u->auth_epoch);
        $this->assertSame($this->a->id, $u->memberships()->value('business_id'));
        $this->assertSame(0, $u->tokens()->count());
        $this->assertSame('Active', $this->other->fresh()->status);
    }
    public function test_assisted_password_reset_confirms_password_revokes_tokens_and_keeps_membership(): void
    {
        $oldOther = $this->other->password;
        app(BusinessContext::class)->run($this->a, fn() => $this->owner->createToken('test'));
        $url = '/platform/users/' . $this->owner->id . '/password';
        $this->actingAs($this->office)->put($url, ['password' => 'new-test-password', 'password_confirmation' => 'different', 'note' => 'Support request'])->assertSessionHasErrors('password');
        $this->put($url, ['password' => 'new-test-password', 'password_confirmation' => 'new-test-password', 'note' => 'Confirmed owner request'])->assertSessionHasNoErrors();
        $u = $this->owner->fresh();
        $this->assertTrue(Hash::check('new-test-password', $u->password));
        $this->assertSame(0, $u->tokens()->count());
        $this->assertSame($this->a->id, $u->memberships()->value('business_id'));
        $this->assertSame($oldOther, $this->other->fresh()->password);
        $this->assertStringNotContainsString('new-test-password', json_encode(PlatformActivityLog::pluck('properties')));
        $this->put('/platform/users/' . $this->office->id . '/password', ['password' => 'new-test-password', 'password_confirmation' => 'new-test-password', 'note' => 'Wrong directory'])->assertNotFound();
    }
    public function test_owner_can_create_office_admin_but_business_admin_cannot(): void
    {
        $d = ['name' => 'New support agent', 'email' => 'agent@example.test', 'status' => 'Active', 'platform_role' => 'support', 'password' => 'a-long-initial-password'];
        $this->actingAs($this->office)->post('/platform/admins', $d)->assertSessionHasNoErrors();
        $u = User::withoutGlobalScopes()->where('email', $d['email'])->firstOrFail();
        $this->assertTrue($u->is_platform_admin);
        $this->assertSame('support', $u->platform_role);
        $this->assertFalse($u->memberships()->exists());
        $this->spaGet('/platform/admins')->assertJsonMissingPath('props.accounts.data.0.office_mfa_secret');
        $this->office->forceFill(['platform_role' => 'admin'])->save();
        $this->actingAs($this->office)->post('/platform/admins', array_replace($d, ['email' => 'forbidden@example.test']))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'forbidden@example.test']);
    }
    public function test_owner_cannot_demote_or_disable_own_account(): void
    {
        $this->actingAs($this->office)->put('/platform/admins/' . $this->office->id, ['name' => $this->office->name, 'email' => $this->office->email, 'status' => 'Inactive', 'platform_role' => 'support'])->assertSessionHasErrors('status');
        $this->assertSame('owner', $this->office->fresh()->platform_role);
        $this->assertSame('Active', $this->office->fresh()->status);
    }
    public function test_office_directory_cannot_edit_tenant_identity(): void
    {
        $this->actingAs($this->office)->put('/platform/admins/' . $this->owner->id, ['name' => 'Attempt', 'email' => 'attempt@example.test', 'status' => 'Active', 'platform_role' => 'owner'])->assertNotFound();
        $this->assertFalse($this->owner->fresh()->is_platform_admin);
    }
    public function test_payment_destinations_are_managed_and_only_active_ones_reach_tenants(): void
    {
        $this->actingAs($this->office)->post('/platform/payment-methods', $this->method())->assertSessionHasNoErrors();
        $m = PlatformPaymentMethod::firstOrFail();
        $this->post('/platform/payment-methods', $this->method(['name' => 'Hidden Wallet', 'type' => 'mobile_wallet', 'is_active' => false]))->assertSessionHasNoErrors();
        $this->actingAs($this->owner);
        $this->spaGet('/billing')->assertJsonCount(1, 'props.payment_methods')->assertJsonPath('props.payment_methods.0.account_number', 'QA-123');
        $this->post('/platform/payment-methods', $this->method())->assertForbidden();
        $this->actingAs($this->office)->put('/platform/payment-methods/' . $m->id, $this->method(['is_active' => false]))->assertSessionHasNoErrors();
        $this->actingAs($this->owner);
        $this->spaGet('/billing')->assertJsonCount(0, 'props.payment_methods');
    }
    public function test_reports_keep_currencies_separate_and_exclude_pending(): void
    {
        $this->paid('USD');
        $this->paid('MMK');
        $this->paid('EUR', false);
        $this->actingAs($this->office);
        $p = $this->spaGet('/platform/reports')->assertOk()->json('props');
        $this->assertCount(2, $p['collections']);
        $this->assertEquals(2500, $p['collections'][0]['amount_minor']);
        $this->assertSame(1, $p['stats']['pending_payments']);
        $this->get('/platform/reports?from=2020-01-01&to=2026-01-01')->assertStatus(422);
    }
    public function test_collection_export_is_filtered_and_escapes_spreadsheet_formulas(): void
    {
        $this->a->update(['name' => '=2+2']);
        $this->paid();
        $this->paid('EUR', false);
        $this->actingAs($this->office);
        $response = $this->get('/platform/reports/export')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=2+2", $csv);
        $this->assertStringContainsString('USD', $csv);
        $this->assertStringNotContainsString('EUR', $csv);
        $this->assertDatabaseHas('platform_activity_logs', ['action' => 'billing.collections_exported']);
    }
    public function test_calendar_lists_effective_access_dates_not_stale_trial_dates(): void
    {
        $s = BusinessSubscription::where('business_id', $this->b->id)->firstOrFail();
        $s->update(['trial_ends_at' => now()->addDay()]);
        $this->actingAs($this->office);
        $p = $this->spaGet('/platform/calendar?month=' . now()->format('Y-m') . '&q=Beta')->assertOk()->json('props');
        $this->assertCount(1, $p['events']);
        $this->assertSame($this->b->id, $p['events'][0]['business_id']);
        $this->assertSame(now()->addDay()->toDateString(), $p['events'][0]['date']);
        $this->get('/platform/calendar?month=invalid')->assertSessionHasErrors('month');
    }
    public function test_audit_filters_by_action_business_and_date(): void
    {
        PlatformActivityLog::create(['actor_id' => $this->office->id, 'business_id' => $this->a->id, 'action' => 'test.alpha', 'properties' => [], 'created_at' => now()]);
        PlatformActivityLog::create(['actor_id' => $this->office->id, 'business_id' => $this->b->id, 'action' => 'test.beta', 'properties' => [], 'created_at' => now()]);
        $this->actingAs($this->office);
        $this->spaGet('/platform/audit?q=Alpha&action=test.alpha&from=' . today()->toDateString())->assertJsonCount(1, 'props.entries.data')->assertJsonPath('props.entries.data.0.action', 'test.alpha');
    }
    public function test_health_returns_checks_without_paths_or_secrets(): void
    {
        $this->actingAs($this->office);
        $r = $this->spaGet('/platform/health')->assertOk()->assertJsonPath('props.health.database', true);
        $json = $r->getContent();
        $this->assertStringNotContainsString(base_path(), $json);
        $this->assertStringNotContainsString(config('app.key'), $json);
        $this->assertStringNotContainsString('DB_PASSWORD', $json);
    }
}
