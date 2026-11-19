<?php

namespace Tests\Feature;

use App\Finance\PlatformLedger;
use App\Billing\SubscriptionService;
use App\Models\{User, Business, SubscriptionPlan, SubscriptionPayment, PlatformBankBook as Book, PlatformFounder as Founder, PlatformFinanceEntry as Entry, PlatformFinanceReceipt as Receipt};
use App\Services\Businesses\ProvisionBusiness;
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Artisan};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class FounderFinanceTest extends TestCase
{
    use RefreshDatabase;
    private User $office;
    private User $owner;
    private User $other;
    private Business $a;
    private Business $b;
    private PlatformLedger $ledger;
    protected function setUp(): void
    {
        parent::setUp();
        config(['operations.mfa_required' => false]);
        $this->ledger = app(PlatformLedger::class);
        $this->office = User::factory()->create();
        $this->office->forceFill(['is_platform_admin' => true])->save();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->a = app(ProvisionBusiness::class)->create(['name' => 'Finance Alpha', 'slug' => 'finance-alpha'], $this->owner);
        $this->b = app(ProvisionBusiness::class)->create(['name' => 'Finance Beta', 'slug' => 'finance-beta'], $this->other);
    }
    private function book(string $name = 'Main USD', string $currency = 'USD', bool $default = true): Book
    {
        return $this->ledger->saveBook(null, ['name' => $name, 'currency' => $currency, 'account_reference' => 'TEST-ONLY', 'is_active' => true, 'is_default' => $default], $this->office);
    }
    private function founders(array $shares = [6000, 4000]): array
    {
        $rows = [];
        foreach ($shares as $i => $bps) {
            $rows[] = ['name' => 'Founder ' . ($i + 1), 'share_bps' => $bps, 'is_active' => true];
        }
        $this->ledger->saveFounders(['version' => 0, 'founders' => $rows], $this->office);
        return Founder::orderBy('created_at')->orderBy('id')->get()->all();
    }
    private function data(string $type, string $amount, ?Book $book = null, array $extra = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'type' => $type, 'currency' => $book?->currency ?? 'USD', 'amount' => $amount, 'entry_date' => today()->toDateString(), 'description' => 'Synthetic ' . $type, 'category' => 'Testing', 'reference' => 'QA-reference', 'counterparty' => null, 'book_id' => $book?->id, 'to_book_id' => null, 'founder_id' => null, 'pay_now' => false, 'settings_version' => (int) DB::table('platform_founder_settings')->where('id', 1)->value('version'), 'direction' => 'increase'], $extra);
    }
    private function entry(string $type, string $amount, ?Book $book = null, array $extra = []): Entry
    {
        return $this->ledger->post($this->data($type, $amount, $book, $extra), $this->office);
    }
    private function invalid(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }
    private function paid(?Business $business = null, bool $approve = true): SubscriptionPayment
    {
        $business ??= $this->a;
        $owner = $business->id === $this->a->id ? $this->owner : $this->other;
        $plan = SubscriptionPlan::create(['code' => 'finance-' . Str::lower(Str::random(8)), 'name' => 'Finance test plan', 'currency' => 'USD', 'amount_minor' => 2500, 'interval_months' => 1, 'grace_days' => 3, 'features' => ['cashier' => true, 'offline_sync' => true, 'reports' => true], 'is_active' => true, 'is_public' => true, 'is_trial' => false]);
        $s = app(SubscriptionService::class);
        $i = $s->invoice($business, $plan, (string) Str::uuid(), $this->office);
        $p = $s->submit($i, $owner, ['request_key' => (string) Str::uuid(), 'method' => 'bank_transfer', 'reference' => 'FIN-QA-' . Str::random(10), 'payment_date' => today()->toDateString()]);
        if ($approve) {
            $s->review($p, $this->office, true, null);
        }
        return $p->fresh();
    }
    public function test_receipt_approval_posts_once_and_default_change_does_not_move_history(): void
    {
        $one = $this->book();
        $p = $this->paid();
        $two = $this->book('Second USD');
        $this->ledger->recordReceipt($p);
        $this->paid($this->b);
        $this->assertSame(2, Receipt::count());
        $this->assertSame($one->id, Receipt::where('payment_id', $p->id)->value('book_id'));
        $s = $this->ledger->summary('USD');
        $this->assertSame(5000, $s['income_minor']);
        foreach ($s['books'] as $b) {
            $this->assertSame(2500, $b->balance_minor);
        }
    }
    public function test_pending_and_rejected_payments_are_not_income(): void
    {
        $p = $this->paid(null, false);
        $this->assertSame(0, Receipt::count());
        $this->assertSame(0, $this->ledger->summary('USD')['profit_minor']);
        app(SubscriptionService::class)->review($p, $this->office, false, 'Not received');
        $this->assertSame(0, Receipt::count());
    }
    public function test_unassigned_receipts_cannot_be_spent_and_are_assigned_only_once(): void
    {
        $p = $this->paid();
        $b = $this->book();
        $s = $this->ledger->summary('USD');
        $this->assertSame(2500, $s['unassigned_minor']);
        $this->assertSame(0, $s['books']->first()->balance_minor);
        $this->invalid(fn() => $this->entry('expense', '1.00', $b), 'amount');
        $r = Receipt::firstOrFail();
        $this->ledger->assignReceipt($r->id, $b->id, $this->office);
        $this->assertSame(2500, $this->ledger->summary('USD')['books']->first()->balance_minor);
        $this->invalid(fn() => $this->ledger->assignReceipt($r->id, $b->id, $this->office), 'book_id');
    }
    public function test_reconciliation_recovers_missing_receipts_without_using_current_default(): void
    {
        $p = $this->paid();
        Receipt::query()->delete();
        $this->book();
        Artisan::call('platform:finance-sync');
        Artisan::call('platform:finance-sync');
        $this->assertSame(1, Receipt::count());
        $this->assertNull(Receipt::first()->book_id);
        $this->assertDatabaseHas('platform_activity_logs', ['action' => 'finance.receipts_reconciled']);
    }
    public function test_migration_imports_historical_approved_receipts_without_fake_books(): void
    {
        $p = $this->paid();
        $m = require database_path('migrations/2026_11_19_000004_create_platform_founder_finance.php');
        $m->down();
        $m->up();
        $this->assertSame(1, Receipt::count());
        $this->assertSame($p->id, Receipt::first()->payment_id);
        $this->assertNull(Receipt::first()->book_id);
        $this->assertSame(0, Book::count());
        $this->assertSame(0, Founder::count());
    }
    public function test_income_expenses_capital_opening_and_transfers_have_distinct_effects(): void
    {
        $f = $this->founders([10000])[0];
        $a = $this->book();
        $b = $this->book('Other USD', 'USD', false);
        $this->entry('opening', '100.00', $a);
        $this->entry('contribution', '20.00', $a, ['founder_id' => $f->id]);
        $this->entry('income', '50.00', $a);
        $this->entry('expense', '10.00', $a);
        $this->entry('transfer', '30.00', $a, ['to_book_id' => $b->id]);
        $s = $this->ledger->summary('USD');
        $this->assertSame(4000, $s['profit_minor']);
        $this->assertSame(16000, $s['cash_minor']);
        $this->assertSame(13000, $s['books']->firstWhere('id', $a->id)->balance_minor);
        $this->assertSame(3000, $s['books']->firstWhere('id', $b->id)->balance_minor);
        $this->assertSame(2000, $s['founders']->first()->capital_minor);
    }
    public function test_posting_is_idempotent_and_altered_replay_is_rejected(): void
    {
        $b = $this->book();
        $d = $this->data('income', '10.25', $b);
        $first = $this->ledger->post($d, $this->office);
        $again = $this->ledger->post($d, $this->office);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Entry::count());
        $this->invalid(fn() => $this->ledger->post(array_replace($d, ['amount' => '20.00']), $this->office), 'request_key');
    }
    public function test_money_precision_and_nonpositive_amounts_are_rejected(): void
    {
        $b = $this->book();
        foreach (['0', '-1', '1.234', '1e2', '9999999999', 'abc'] as $v) {
            $this->invalid(fn() => $this->entry('income', $v, $b), 'amount');
        }
        $this->entry('income', '0.01', $b);
        $this->assertSame(1, $this->ledger->summary('USD')['income_minor']);
    }
    public function test_overdraft_self_transfer_and_cross_currency_transfer_are_rejected(): void
    {
        $a = $this->book();
        $b = $this->book('MMK', 'MMK');
        $this->entry('income', '10.00', $a);
        $this->invalid(fn() => $this->entry('expense', '10.01', $a), 'amount');
        $this->invalid(fn() => $this->entry('transfer', '1.00', $a, ['to_book_id' => $a->id]), 'to_book_id');
        $this->invalid(fn() => $this->entry('transfer', '1.00', $a, ['to_book_id' => $b->id]), 'book_id');
        $this->assertSame(1000, $this->ledger->summary('USD')['cash_minor']);
        $this->assertSame(0, $this->ledger->summary('MMK')['cash_minor']);
    }
    public function test_founder_shares_require_exact_total_and_stale_edits_fail(): void
    {
        $this->invalid(fn() => $this->ledger->saveFounders(['version' => 0, 'founders' => [['name' => 'A', 'share_bps' => 9000, 'is_active' => true]]], $this->office), 'founders');
        $this->assertSame(0, Founder::count());
        $this->founders();
        $this->invalid(fn() => $this->ledger->saveFounders(['version' => 0, 'founders' => []], $this->office), 'version');
        $this->assertSame(2, Founder::count());
    }
    public function test_allocations_preserve_snapshots_and_do_not_move_cash_until_paid(): void
    {
        $founders = $this->founders();
        $b = $this->book();
        $this->entry('income', '100.00', $b);
        $e = $this->entry('allocation', '60.00');
        $s = $this->ledger->summary('USD');
        $this->assertSame(10000, $s['cash_minor']);
        $this->assertSame(4000, $s['unallocated_minor']);
        $this->assertSame(6000, $s['founders']->sum('owed_minor'));
        $old = DB::table('platform_profit_shares')->where('entry_id', $e->id)->get()->toJson();
        $rows = Founder::all()->map(fn($f, $i) => ['id' => $f->id, 'name' => 'Renamed ' . $i, 'share_bps' => $i === 0 ? 7000 : 3000, 'is_active' => true])->all();
        $this->ledger->saveFounders(['version' => 1, 'founders' => $rows], $this->office);
        $this->assertSame($old, DB::table('platform_profit_shares')->where('entry_id', $e->id)->get()->toJson());
        $this->invalid(fn() => $this->ledger->post($this->data('allocation', '1.00', null, ['settings_version' => 1]), $this->office), 'settings_version');
    }
    public function test_allocation_rounding_preserves_every_cent_and_zero_share_gets_zero(): void
    {
        $this->founders([5000, 5000, 0]);
        $b = $this->book();
        $this->entry('income', '0.03', $b);
        $e = $this->entry('allocation', '0.03');
        $shares = DB::table('platform_profit_shares')->where('entry_id', $e->id)->get();
        $this->assertEquals(3, $shares->sum('amount_minor'));
        $this->assertEquals(0, $shares->firstWhere('share_bps', 0)->amount_minor);
        $this->assertSame([1, 2], $shares->where('share_bps', 5000)->pluck('amount_minor')->map(fn($v) => (int) $v)->sort()->values()->all());
    }
    public function test_allocations_cannot_exceed_profit_and_cannot_use_capital_as_profit(): void
    {
        $this->founders([10000]);
        $b = $this->book();
        $this->entry('opening', '100.00', $b);
        $this->invalid(fn() => $this->entry('allocation', '1.00'), 'amount');
        $this->entry('income', '10.00', $b);
        $this->entry('expense', '3.00', $b);
        $this->invalid(fn() => $this->entry('allocation', '7.01'), 'amount');
    }
    public function test_combined_allocation_pays_all_shares_exactly_once(): void
    {
        $this->founders();
        $b = $this->book();
        $this->entry('income', '100.00', $b);
        $d = $this->data('allocation', '60.00', $b, ['pay_now' => true, 'description' => str_repeat('A', 500)]);
        $e = $this->ledger->post($d, $this->office);
        $this->ledger->post($d, $this->office);
        $s = $this->ledger->summary('USD');
        $this->assertSame(4000, $s['cash_minor']);
        $this->assertSame(0, $s['founders']->sum('owed_minor'));
        $this->assertSame(6000, $s['founders']->sum('paid_minor'));
        $this->assertSame(2, Entry::where('parent_entry_id', $e->id)->count());
    }
    public function test_combined_allocation_needs_cash_in_selected_book(): void
    {
        $this->founders();
        $a = $this->book();
        $b = $this->book('Empty USD', 'USD', false);
        $this->entry('income', '100.00', $a);
        $this->invalid(fn() => $this->entry('allocation', '50.00', $b, ['pay_now' => true]), 'amount');
        $this->assertSame(0, Entry::where('type', 'allocation')->count());
    }
    public function test_payout_limits_and_capital_returns_do_not_reduce_profit(): void
    {
        $f = $this->founders([10000])[0];
        $b = $this->book();
        $this->entry('income', '50.00', $b);
        $this->entry('contribution', '20.00', $b, ['founder_id' => $f->id]);
        $this->entry('allocation', '30.00');
        $this->entry('payout', '10.00', $b, ['founder_id' => $f->id]);
        $this->entry('capital_return', '5.00', $b, ['founder_id' => $f->id]);
        $s = $this->ledger->summary('USD');
        $this->assertSame(5000, $s['profit_minor']);
        $this->assertSame(5500, $s['cash_minor']);
        $this->assertSame(2000, $s['founders']->first()->owed_minor);
        $this->assertSame(1500, $s['founders']->first()->capital_minor);
        $this->invalid(fn() => $this->entry('payout', '20.01', $b, ['founder_id' => $f->id]), 'amount');
        $this->invalid(fn() => $this->entry('capital_return', '15.01', $b, ['founder_id' => $f->id]), 'amount');
    }
    public function test_archived_founders_keep_statement_and_can_receive_owed_profit(): void
    {
        $f = $this->founders([10000])[0];
        $b = $this->book();
        $this->entry('income', '10.00', $b);
        $this->entry('allocation', '10.00');
        $this->ledger->saveFounders(['version' => 1, 'founders' => [['id' => $f->id, 'name' => $f->name, 'share_bps' => 10000, 'is_active' => false]]], $this->office);
        $this->entry('payout', '10.00', $b, ['founder_id' => $f->id]);
        $this->assertSame(0, $this->ledger->summary('USD')['founders']->first()->owed_minor);
        $this->invalid(fn() => $this->entry('allocation', '0.01'), 'founders');
    }
    public function test_void_preserves_history_and_prevents_reversing_spent_income(): void
    {
        $b = $this->book();
        $income = $this->entry('income', '10.00', $b);
        $expense = $this->entry('expense', '9.00', $b);
        $this->invalid(fn() => $this->ledger->void($income, 'Correction', $this->office), 'reason');
        $this->ledger->void($expense, 'Wrong expense', $this->office);
        $this->ledger->void($income, 'Duplicate income', $this->office);
        $this->ledger->void($income, 'Idempotent', $this->office);
        $this->assertSame(2, Entry::count());
        $this->assertSame(2, Entry::whereNotNull('voided_at')->count());
        $this->assertSame(0, $this->ledger->summary('USD')['cash_minor']);
    }
    public function test_void_transfer_requires_receiving_funds_and_restores_both_books(): void
    {
        $a = $this->book();
        $b = $this->book('Other USD', 'USD', false);
        $this->entry('income', '10.00', $a);
        $t = $this->entry('transfer', '4.00', $a, ['to_book_id' => $b->id]);
        $e = $this->entry('expense', '3.00', $b);
        $this->invalid(fn() => $this->ledger->void($t, 'Wrong transfer', $this->office), 'reason');
        $this->ledger->void($e, 'Wrong expense', $this->office);
        $this->ledger->void($t, 'Wrong transfer', $this->office);
        $s = $this->ledger->summary('USD');
        $this->assertSame(1000, $s['books']->firstWhere('id', $a->id)->balance_minor);
        $this->assertSame(0, $s['books']->firstWhere('id', $b->id)->balance_minor);
    }
    public function test_void_allocation_cannot_orphan_payouts_and_combined_void_reverses_children(): void
    {
        $f = $this->founders([10000])[0];
        $b = $this->book();
        $this->entry('income', '100.00', $b);
        $a = $this->entry('allocation', '40.00');
        $p = $this->entry('payout', '20.00', $b, ['founder_id' => $f->id]);
        $this->invalid(fn() => $this->ledger->void($a, 'Wrong allocation', $this->office), 'reason');
        $this->ledger->void($p, 'Wrong payout', $this->office);
        $this->ledger->void($a, 'Wrong allocation', $this->office);
        $combined = $this->entry('allocation', '60.00', $b, ['pay_now' => true]);
        $this->ledger->void($combined, 'Wrong combined allocation', $this->office);
        $s = $this->ledger->summary('USD');
        $this->assertSame(10000, $s['cash_minor']);
        $this->assertSame(0, $s['allocated_minor']);
        $this->assertSame(0, $s['founders']->first()->owed_minor);
    }
    public function test_bank_currency_and_default_archive_are_protected(): void
    {
        $b = $this->book();
        $this->invalid(fn() => $this->ledger->saveBook($b, ['name' => 'Changed', 'currency' => 'MMK', 'is_active' => true, 'is_default' => true], $this->office), 'currency');
        $this->invalid(fn() => $this->ledger->saveBook($b, ['name' => 'Archived', 'currency' => 'USD', 'is_active' => false, 'is_default' => false], $this->office), 'is_default');
        $this->book('Replacement');
        $this->ledger->saveBook($b, ['name' => $b->name, 'currency' => 'USD', 'is_active' => false, 'is_default' => false], $this->office);
        $this->assertFalse($b->fresh()->is_active);
    }
    public function test_finance_permissions_and_owner_only_financial_actions(): void
    {
        $b = $this->book();
        $this->actingAs($this->owner);
        foreach (['/platform/finance', '/platform/founders', '/platform/bank-books'] as $u) {
            $this->get($u)->assertForbidden();
        }
        $this->office->forceFill(['platform_role' => 'billing'])->save();
        $this->actingAs($this->office);
        foreach (['/platform/finance', '/platform/founders', '/platform/bank-books'] as $u) {
            $this->get($u)->assertOk();
        }
        $this->put('/platform/founders', ['version' => 0, 'founders' => []])->assertForbidden();
        $this->post('/platform/finance/entries', $this->data('opening', '10.00', $b))->assertForbidden();
        $this->post('/platform/finance/entries', $this->data('income', '10.00', $b))->assertSessionHasNoErrors();
        $this->office->forceFill(['platform_role' => 'support'])->save();
        $this->actingAs($this->office)->get('/platform/finance')->assertForbidden();
    }
    public function test_http_forms_union_filters_and_founder_statement_share_amount(): void
    {
        $founders = $this->founders();
        $b = $this->book();
        $this->actingAs($this->office)->post('/platform/finance/entries', $this->data('income', '100.00', $b))->assertSessionHasNoErrors();
        $a = $this->entry('allocation', '10.00');
        $f = DB::table('platform_profit_shares')->where('entry_id', $a->id)->first();
        $this->get('/platform/finance?founder_id=' . $f->founder_id, ['X-SPA' => 'true'])->assertOk()->assertJsonPath('props.entries.total', 1)->assertJsonPath('props.entries.data.0.founder_share_minor', (int) $f->amount_minor);
        $this->get('/platform/finance?type=expense', ['X-SPA' => 'true'])->assertJsonPath('props.entries.total', 0);
        $this->get('/platform/finance?type=allocation', ['X-SPA' => 'true'])->assertJsonCount(2, 'props.entries.data.0.shares');
        $this->post('/platform/finance/entries', $this->data('allocation', '1.00', null, ['entry_date' => today()->subDay()->toDateString()]))->assertSessionHasErrors('entry_date');
    }
    public function test_bank_book_creation_and_csv_escape_are_verified_through_http(): void
    {
        $this->actingAs($this->office)->post('/platform/bank-books', ['name' => 'HTTP Book', 'currency' => 'USD', 'account_reference' => 'TEST', 'is_active' => true, 'is_default' => true])->assertRedirect(route('platform.bank-books.index', ['currency' => 'USD']))->assertSessionHasNoErrors();
        $b = Book::firstOrFail();
        $this->entry('income', '10.00', $b, ['description' => '=2+2']);
        $this->entry('income', '20.00', $b, ['description' => 'Other row']);
        $r = $this->get('/platform/finance/export?q=%3D2%2B2')->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString("'=2+2", $csv);
        $this->assertStringNotContainsString('Other row', $csv);
        $this->assertDatabaseHas('platform_activity_logs', ['action' => 'finance.ledger_exported']);
    }
    public function test_failed_receipt_post_rolls_back_payment_approval_and_period(): void
    {
        $payment = $this->paid(null, false);
        $event = 'eloquent.creating: ' . Receipt::class;
        $dispatcher = Receipt::getEventDispatcher();
        $listeners = $dispatcher->getRawListeners()[$event] ?? [];
        Receipt::creating(function () {
            throw new \RuntimeException('Synthetic receipt failure');
        });
        try {
            app(SubscriptionService::class)->review($payment, $this->office, true, null);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic receipt failure', $e->getMessage());
        } finally {
            $dispatcher->forget($event);
            foreach ($listeners as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $payment->invoice_id, 'status' => 'open']);
        $this->assertDatabaseMissing('subscription_periods', ['invoice_id' => $payment->invoice_id]);
    }
    public function test_nonfinance_admin_cannot_read_finance_audit_details(): void
    {
        $book = $this->book();
        $this->entry('income', '10.00', $book);
        $this->office->forceFill(['platform_role' => 'admin'])->save();
        $this->actingAs($this->office);
        $audit = $this->get('/platform/audit', ['X-SPA' => 'true'])->assertOk()->json('props');
        $this->assertFalse(collect($audit['entries']['data'])->contains(fn($row) => str_starts_with($row['action'], 'finance.')));
        $this->assertFalse(collect($audit['actions'])->contains(fn($action) => str_starts_with($action, 'finance.')));
        $activity = $this->get('/platform/businesses', ['X-SPA' => 'true'])->assertOk()->json('props.businesses.data');
        foreach ($activity as $row) {
            $this->assertArrayNotHasKey('properties', $row);
        }
    }
    public function test_decreasing_opening_balance_changes_cash_without_changing_profit(): void
    {
        $b = $this->book();
        $this->entry('opening', '10.00', $b);
        $this->entry('opening', '2.50', $b, ['direction' => 'decrease']);
        $s = $this->ledger->summary('USD');
        $this->assertSame(750, $s['cash_minor']);
        $this->assertSame(0, $s['profit_minor']);
        $this->invalid(fn() => $this->entry('opening', '7.51', $b, ['direction' => 'decrease']), 'amount');
    }
    public function test_finance_export_is_not_blocked_by_normal_journal_posting(): void
    {
        $book = $this->book();
        $this->actingAs($this->office);
        for ($i = 0; $i < 6; $i++) {
            $this->post('/platform/finance/entries', $this->data('income', '1.00', $book))->assertSessionHasNoErrors();
        }
        $this->get('/platform/finance/export')->assertOk();
    }
}
