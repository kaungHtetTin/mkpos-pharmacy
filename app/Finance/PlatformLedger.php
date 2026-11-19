<?php

namespace App\Finance;

use App\Models\{PlatformFounder as Founder, PlatformBankBook as Book, PlatformFinanceReceipt as Receipt, PlatformFinanceEntry as Entry, SubscriptionPayment, User};
use App\Support\PlatformAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class PlatformLedger
{
    public const TYPES = ['income', 'expense', 'contribution', 'capital_return', 'opening', 'transfer', 'allocation', 'payout'];
    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
    private function lockCurrency(string $currency): object
    {
        DB::table('platform_finance_currencies')->insertOrIgnore(['currency' => $currency]);
        return DB::table('platform_finance_currencies')->where('currency', $currency)->lockForUpdate()->first();
    }
    public function amount(string $value): int
    {
        if (!preg_match('/^(0|[1-9]\d{0,8})(?:\.(\d{1,2}))?$/D', $value, $m)) {
            $this->invalid('amount', 'Enter an amount with at most two decimal places.');
        }
        $minor = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($minor < 1) {
            $this->invalid('amount', 'Enter an amount greater than zero.');
        }
        return $minor;
    }
    public function founders(): array
    {
        return ['version' => (int) DB::table('platform_founder_settings')->where('id', 1)->value('version'), 'items' => Founder::orderBy('created_at')->orderBy('id')->get()];
    }
    public function saveFounders(array $data, User $actor): void
    {
        DB::transaction(function () use ($data, $actor) {
            $settings = DB::table('platform_founder_settings')->where('id', 1)->lockForUpdate()->first();
            if ((int) $data['version'] !== (int) $settings->version) {
                $this->invalid('version', 'Founder shares changed. Reload before saving.');
            }
            $rows = collect($data['founders']);
            if ($rows->where('is_active', true)->isNotEmpty() && (int) $rows->where('is_active', true)->sum('share_bps') !== 10000) {
                $this->invalid('founders', 'Active founder shares must total exactly 100%.');
            }
            $ids = $rows->pluck('id')->filter();
            if ($ids->unique()->count() !== $ids->count() || Founder::whereIn('id', $ids)->count() !== $ids->count()) {
                $this->invalid('founders', 'Invalid or duplicate founder.');
            }
            Founder::whereNotIn('id', $ids)->update(['is_active' => false]);
            foreach ($rows as $row) {
                $f = isset($row['id']) ? Founder::findOrFail($row['id']) : new Founder();
                $f->fill(collect($row)->only('name', 'share_bps', 'is_active')->all())->save();
            }
            DB::table('platform_founder_settings')->where('id', 1)->update(['version' => $settings->version + 1]);
            PlatformAudit::record('finance.founder_shares_updated', $actor, null, ['version' => $settings->version + 1, 'active_founders' => $rows->where('is_active', true)->count()]);
        }, 3);
    }
    public function saveBook(?Book $book, array $data, User $actor): Book
    {
        return DB::transaction(function () use ($book, $data, $actor) {
            $control = $this->lockCurrency($data['currency']);
            if ($book?->exists) {
                $book = Book::lockForUpdate()->findOrFail($book->id);
                if ($book->currency !== $data['currency']) {
                    $this->invalid('currency', 'A bank book currency cannot be changed. Create another book.');
                }
            } else {
                $book = new Book();
            }
            if ($book->exists && $control->default_book_id === $book->id && !$data['is_default']) {
                $this->invalid('is_default', 'Choose another receiving book before removing this default.');
            }
            if (!$data['is_active'] && $control->default_book_id === $book->id) {
                $this->invalid('is_active', 'Choose another subscription receiving book before archiving this book.');
            }
            if ($data['is_default'] && !$data['is_active']) {
                $this->invalid('is_default', 'The receiving book must be active.');
            }
            $book->fill(collect($data)->only('name', 'currency', 'account_reference', 'is_active')->all())->save();
            if ($data['is_default']) {
                DB::table('platform_finance_currencies')->where('currency', $data['currency'])->update(['default_book_id' => $book->id]);
            }
            PlatformAudit::record('finance.bank_book_saved', $actor, null, ['book_id' => $book->id, 'currency' => $book->currency, 'is_default' => $data['is_default'], 'active' => $book->is_active]);
            return $book;
        }, 3);
    }
    /** Unique payment IDs make live approvals and reconciliation safe to retry. */
    public function recordReceipt(SubscriptionPayment $payment, bool $useDefault = true): bool
    {
        if ($payment->status !== 'approved') {
            return false;
        }
        return DB::transaction(function () use ($payment, $useDefault) {
            $control = $this->lockCurrency($payment->currency);
            $book = $useDefault && $control->default_book_id ? Book::whereKey($control->default_book_id)->where('currency', $payment->currency)->where('is_active', true)->first() : null;
            $receipt = Receipt::firstOrCreate(['payment_id' => $payment->id], ['book_id' => $book?->id, 'currency' => $payment->currency, 'amount_minor' => $payment->amount_minor, 'received_at' => $payment->reviewed_at ?? now(), 'assigned_by' => $book ? $payment->reviewed_by : null, 'assigned_at' => $book ? now() : null]);
            return $receipt->wasRecentlyCreated;
        }, 3);
    }
    public function assignReceipt(string $id, string $bookId, User $actor): void
    {
        $receipt = Receipt::findOrFail($id);
        DB::transaction(function () use ($receipt, $bookId, $actor) {
            $this->lockCurrency($receipt->currency);
            $receipt = Receipt::lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->book_id) {
                $this->invalid('book_id', 'This receipt is already assigned. Record a transfer to move funds.');
            }
            $book = $this->book($bookId, $receipt->currency);
            $receipt->update(['book_id' => $book->id, 'assigned_by' => $actor->id, 'assigned_at' => now()]);
            PlatformAudit::record('finance.receipt_assigned', $actor, null, ['receipt_id' => $receipt->id, 'book_id' => $book->id]);
        }, 3);
    }
    private function book(?string $id, string $currency): Book
    {
        $book = $id ? Book::whereKey($id)->where('currency', $currency)->where('is_active', true)->first() : null;
        if (!$book) {
            $this->invalid('book_id', 'Choose an active bank book in the selected currency.');
        }
        return $book;
    }
    /** All-time, currency-separated cash and profit; allocations themselves never move cash. */
    public function summary(string $currency): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn() => $this->summary($currency));
        }
        $totals = Entry::where('currency', $currency)->whereNull('voided_at')->select('type')->selectRaw('SUM(amount_minor) as total')->groupBy('type')->pluck('total', 'type');
        $v = fn($type) => (int) ($totals[$type] ?? 0);
        $receipts = (int) Receipt::where('currency', $currency)->sum('amount_minor');
        $income = $receipts + $v('income');
        $profit = $income - $v('expense');
        $books = Book::where('currency', $currency)->orderBy('name')->get();
        $default = DB::table('platform_finance_currencies')->where('currency', $currency)->value('default_book_id');
        $entries = Entry::where('currency', $currency)->whereNull('voided_at')->select('book_id', 'to_book_id', 'type')->selectRaw('SUM(amount_minor) as total')->groupBy('book_id', 'to_book_id', 'type')->get();
        $balances = Receipt::where('currency', $currency)->whereNotNull('book_id')->select('book_id')->selectRaw('SUM(amount_minor) as total')->groupBy('book_id')->pluck('total', 'book_id')->map(fn($n) => (int) $n)->all();
        foreach ($entries as $e) {
            $n = (int) $e->total;
            if ($e->book_id && $e->type !== 'allocation') {
                $balances[$e->book_id] = ($balances[$e->book_id] ?? 0) + (in_array($e->type, ['expense', 'payout', 'capital_return', 'transfer']) ? -$n : $n);
            }
            if ($e->type === 'transfer') {
                $balances[$e->to_book_id] = ($balances[$e->to_book_id] ?? 0) + $n;
            }
        }
        $founders = Founder::orderBy('created_at')->orderBy('id')->get();
        $shares = DB::table('platform_profit_shares as s')->join('platform_finance_entries as e', 'e.id', '=', 's.entry_id')->where('e.currency', $currency)->whereNull('e.voided_at')->select('s.founder_id')->selectRaw('SUM(s.amount_minor) as total')->groupBy('s.founder_id')->pluck('total', 's.founder_id');
        $founderEntries = Entry::where('currency', $currency)->whereNull('voided_at')->whereNotNull('founder_id')->select('founder_id', 'type')->selectRaw('SUM(amount_minor) as total')->groupBy('founder_id', 'type')->get()->groupBy('founder_id');
        foreach ($founders as $f) {
            $t = ($founderEntries->get($f->id) ?? collect())->pluck('total', 'type');
            $f->allocated_minor = (int) ($shares[$f->id] ?? 0);
            $f->paid_minor = (int) ($t['payout'] ?? 0);
            $f->owed_minor = $f->allocated_minor - $f->paid_minor;
            $f->capital_minor = (int) ($t['contribution'] ?? 0) - (int) ($t['capital_return'] ?? 0);
        }
        foreach ($books as $b) {
            $b->balance_minor = $balances[$b->id] ?? 0;
            $b->is_default = $b->id === $default;
        }
        return ['currency' => $currency, 'income_minor' => $income, 'expenses_minor' => $v('expense'), 'profit_minor' => $profit, 'allocated_minor' => $v('allocation'), 'unallocated_minor' => $profit - $v('allocation'), 'cash_minor' => $income + $v('contribution') - $v('expense') - $v('payout') - $v('capital_return') + $v('opening'), 'unassigned_minor' => (int) Receipt::where('currency', $currency)->whereNull('book_id')->sum('amount_minor'), 'books' => $books, 'founders' => $founders, 'settings_version' => (int) DB::table('platform_founder_settings')->where('id', 1)->value('version')];
    }
    /** Lock order: founder settings, currency, then entries; snapshots never follow later share edits. */
    public function post(array $data, User $actor): Entry
    {
        $amount = $this->amount((string) $data['amount']);
        $currency = $data['currency'];
        $type = $data['type'];
        if (!in_array($type, self::TYPES, true)) {
            $this->invalid('type', 'Invalid entry type.');
        }
        if (in_array($type, ['allocation', 'payout', 'capital_return', 'opening']) && $actor->platform_role !== 'owner') {
            abort(403);
        }
        // Canonical request hash prevents altered replay while allowing a lost-response retry.
        $canonical = collect($data)->except('request_key')->sortKeys()->all();
        $hash = hash('sha256', json_encode($canonical));
        return DB::transaction(function () use ($data, $actor, $amount, $currency, $type, $hash) {
            $settings = DB::table('platform_founder_settings')->where('id', 1)->lockForUpdate()->first();
            $this->lockCurrency($currency);
            if ($existing = Entry::where('request_key', $data['request_key'])->first()) {
                if (!hash_equals($existing->request_hash, $hash)) {
                    $this->invalid('request_key', 'This request was already used with different details.');
                }
                return $existing;
            }
            $s = $this->summary($currency);
            $book = null;
            $to = null;
            $founder = null;
            if ($type !== 'allocation' || ($data['pay_now'] ?? false)) {
                $book = $this->book($data['book_id'] ?? null, $currency);
            }
            if ($type === 'transfer') {
                $to = $this->book($data['to_book_id'] ?? null, $currency);
                if ($book->id === $to->id) {
                    $this->invalid('to_book_id', 'Choose a different receiving bank book.');
                }
            }
            if (in_array($type, ['contribution', 'capital_return', 'payout'])) {
                $founder = $s['founders']->firstWhere('id', $data['founder_id'] ?? '');
                if (!$founder || $type === 'contribution' && !$founder->is_active) {
                    $this->invalid('founder_id', 'Choose an eligible founder.');
                }
            }
            if ($type === 'payout' && $amount > $founder->owed_minor) {
                $this->invalid('amount', 'Amount exceeds this founder’s unpaid allocation.');
            }
            if ($type === 'capital_return' && $amount > $founder->capital_minor) {
                $this->invalid('amount', 'Amount exceeds this founder’s recorded capital.');
            }
            if ($type === 'allocation') {
                if ((int) ($data['settings_version'] ?? -1) !== (int) $settings->version) {
                    $this->invalid('settings_version', 'Founder shares changed. Reload before allocating.');
                }
                $active = $s['founders']->where('is_active', true);
                if ($active->isEmpty() || $active->sum('share_bps') !== 10000) {
                    $this->invalid('founders', 'Configure active founder shares totalling 100% first.');
                }
                if ($amount > $s['unallocated_minor']) {
                    $this->invalid('amount', 'Amount exceeds unallocated profit.');
                }
            }
            $signed = $type === 'opening' && ($data['direction'] ?? 'increase') === 'decrease' ? -$amount : $amount;
            $out = in_array($type, ['expense', 'payout', 'capital_return', 'transfer']) || $type === 'allocation' && ($data['pay_now'] ?? false) || $type === 'opening' && $signed < 0;
            if ($out) {
                $balance = $s['books']->firstWhere('id', $book->id)->balance_minor;
                if ($amount > $balance) {
                    $this->invalid('amount', 'The selected bank book has insufficient recorded funds.');
                }
            }
            $entry = Entry::create(['request_key' => $data['request_key'], 'request_hash' => $hash, 'type' => $type, 'currency' => $currency, 'amount_minor' => $signed, 'entry_date' => $data['entry_date'], 'book_id' => $type === 'allocation' ? null : $book?->id, 'to_book_id' => $to?->id, 'founder_id' => $founder?->id, 'description' => $data['description'], 'category' => $data['category'] ?? null, 'reference' => $data['reference'] ?? null, 'counterparty' => $data['counterparty'] ?? null, 'created_by' => $actor->id]);
            if ($type === 'allocation') {
                $active = $active->values();
                $split = [];
                $remaining = $amount;
                foreach ($active as $f) {
                    $n = intdiv($amount * $f->share_bps, 10000);
                    $split[$f->id] = $n;
                    $remaining -= $n;
                }
                $ranked = $active->filter(fn($f) => $f->share_bps > 0)->sort(function ($a, $b) use ($amount) {
                    $ra = $amount * $a->share_bps % 10000;
                    $rb = $amount * $b->share_bps % 10000;
                    return $rb <=> $ra ?: strcmp($a->id, $b->id);
                })->values();
                for ($i = 0; $i < $remaining; $i++) {
                    $split[$ranked[$i]->id]++;
                }
                foreach ($active as $f) {
                    $share = $split[$f->id];
                    DB::table('platform_profit_shares')->insert(['id' => (string) Str::uuid(), 'entry_id' => $entry->id, 'founder_id' => $f->id, 'founder_name' => $f->name, 'share_bps' => $f->share_bps, 'amount_minor' => $share, 'created_at' => now(), 'updated_at' => now()]);
                    if (($data['pay_now'] ?? false) && $share > 0) {
                        Entry::create(['request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', $entry->id . $f->id), 'type' => 'payout', 'currency' => $currency, 'amount_minor' => $share, 'entry_date' => $data['entry_date'], 'book_id' => $book->id, 'founder_id' => $f->id, 'parent_entry_id' => $entry->id, 'description' => 'Payout · ' . Str::limit($data['description'], 480, ''), 'created_by' => $actor->id]);
                    }
                }
            }
            PlatformAudit::record('finance.entry_posted', $actor, null, ['entry_id' => $entry->id, 'type' => $type, 'currency' => $currency, 'amount_minor' => $signed, 'pay_now' => (bool) ($data['pay_now'] ?? false)]);
            return $entry;
        }, 3);
    }
    /** Reverse in place with history retained, checking dependent cash and founder obligations. */
    public function void(Entry $entry, string $reason, User $actor): void
    {
        if ($actor->platform_role !== 'owner') {
            abort(403);
        }
        DB::transaction(function () use ($entry, $reason, $actor) {
            DB::table('platform_founder_settings')->where('id', 1)->lockForUpdate()->first();
            $this->lockCurrency($entry->currency);
            $entry = Entry::lockForUpdate()->findOrFail($entry->id);
            if ($entry->voided_at) {
                return;
            }
            $before = $this->summary($entry->currency);
            $ids = [$entry->id];
            if ($entry->type === 'allocation') {
                $ids = array_merge($ids, Entry::where('parent_entry_id', $entry->id)->whereNull('voided_at')->pluck('id')->all());
            }
            Entry::whereIn('id', $ids)->whereNull('voided_at')->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            $after = $this->summary($entry->currency);
            foreach ($after['books'] as $b) {
                if ($b->balance_minor < 0) {
                    $this->invalid('reason', 'Reversing this entry would leave a bank book below zero. Reverse dependent spending first.');
                }
            }
            foreach ($after['founders'] as $f) {
                if ($f->owed_minor < 0 || $f->capital_minor < 0) {
                    $this->invalid('reason', 'Reverse dependent founder payouts or capital returns before voiding this entry.');
                }
            }
            PlatformAudit::record('finance.entry_voided', $actor, null, ['entry_id' => $entry->id, 'type' => $entry->type, 'reason' => $reason, 'reversed_entries' => count($ids)]);
        }, 3);
    }
}
