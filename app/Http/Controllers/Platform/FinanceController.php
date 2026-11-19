<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Finance\PlatformLedger;
use App\Models\{PlatformFinanceEntry as Entry, PlatformBankBook as Book};
use App\Support\{Spa, PlatformAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class FinanceController extends Controller
{
    private function currency(Request $r): string
    {
        return $r->validate(['currency' => 'nullable|string|size:3|regex:/^[A-Z]{3}$/'])['currency'] ?? 'USD';
    }
    private function currencies(): array
    {
        return DB::table('platform_finance_currencies')->pluck('currency')->merge(DB::table('platform_bank_books')->pluck('currency'))->push('USD')->unique()->sort()->values()->all();
    }
    public function index(Request $r, PlatformLedger $ledger)
    {
        $c = $this->currency($r);
        $f = $this->filters($r);
        $summary = $ledger->summary($c);
        $entries = $this->rows($c, $f)->orderByDesc('entry_date')->orderByDesc('created_at')->paginate(25)->withQueryString();
        $shares = DB::table('platform_profit_shares')->whereIn('entry_id', collect($entries->items())->where('source', 'manual')->pluck('id'))->get()->groupBy('entry_id');
        $entries->through(fn($e) => array_merge((array) $e, ['shares' => $shares->get($e->id) ?? []]));
        return Spa::render('Platform/Finance', ['current_date' => today()->toDateString(), 'summary' => $summary, 'entries' => $entries, 'filters' => array_merge($f, ['currency' => $c]), 'currencies' => $this->currencies(), 'owner' => $r->user()->platform_role === 'owner']);
    }
    private function filters(Request $r): array
    {
        return $r->validate(['q' => 'nullable|string|max:100', 'type' => ['nullable', Rule::in(array_merge(PlatformLedger::TYPES, ['subscription']))], 'book_id' => 'nullable|uuid|exists:platform_bank_books,id', 'founder_id' => 'nullable|uuid|exists:platform_founders,id', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'status' => 'nullable|in:posted,voided,all']);
    }
    private function rows(string $c, array $f)
    {
        $manual = DB::table('platform_finance_entries as e')->leftJoin('platform_bank_books as b', 'b.id', '=', 'e.book_id')->leftJoin('platform_bank_books as t', 't.id', '=', 'e.to_book_id')->leftJoin('platform_founders as f', 'f.id', '=', 'e.founder_id')->leftJoin('users as u', 'u.id', '=', 'e.created_by')->where('e.currency', $c);
        if (($f['status'] ?? 'posted') === 'posted') {
            $manual->whereNull('e.voided_at');
        } elseif (($f['status'] ?? '') === 'voided') {
            $manual->whereNotNull('e.voided_at');
        }
        $manual->when($f['type'] ?? null, fn($q, $v) => $q->where('e.type', $v))->when($f['book_id'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('e.book_id', $v)->orWhere('e.to_book_id', $v)))->when($f['founder_id'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('e.founder_id', $v)->orWhereExists(fn($q) => $q->selectRaw('1')->from('platform_profit_shares as s')->whereColumn('s.entry_id', 'e.id')->where('s.founder_id', $v))))->when($f['from'] ?? null, fn($q, $v) => $q->where('e.entry_date', '>=', $v))->when($f['to'] ?? null, fn($q, $v) => $q->where('e.entry_date', '<=', $v))->when($f['q'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('e.description', 'like', '%' . $v . '%')->orWhere('e.category', 'like', '%' . $v . '%')->orWhere('e.reference', 'like', '%' . $v . '%')->orWhere('e.counterparty', 'like', '%' . $v . '%')));
        $manual->selectRaw("e.id, 'manual' as source, e.type, e.entry_date, e.created_at, e.currency, e.amount_minor, e.description, e.category, e.reference, e.counterparty, e.book_id, b.name as book_name, e.to_book_id, t.name as to_book_name, e.founder_id, f.name as founder_name, u.name as actor_name, e.voided_at, e.void_reason, e.parent_entry_id");
        $receipts = DB::table('platform_finance_receipts as r')->join('subscription_payments as p', 'p.id', '=', 'r.payment_id')->join('businesses as business', 'business.id', '=', 'p.business_id')->leftJoin('platform_bank_books as b', 'b.id', '=', 'r.book_id')->leftJoin('users as u', 'u.id', '=', 'p.reviewed_by')->where('r.currency', $c);
        if (($f['status'] ?? '') === 'voided' || !empty($f['founder_id']) || !empty($f['type']) && $f['type'] !== 'subscription') {
            $receipts->whereRaw('1=0');
        }
        $receipts->when($f['book_id'] ?? null, fn($q, $v) => $q->where('r.book_id', $v))->when($f['from'] ?? null, fn($q, $v) => $q->where('r.received_at', '>=', $v . ' 00:00:00'))->when($f['to'] ?? null, fn($q, $v) => $q->where('r.received_at', '<=', $v . ' 23:59:59'))->when($f['q'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('business.name', 'like', '%' . $v . '%')->orWhere('p.reference', 'like', '%' . $v . '%')))->selectRaw("r.id, 'subscription' as source, 'subscription' as type, DATE(r.received_at) as entry_date, r.created_at, r.currency, r.amount_minor, CONCAT('Subscription · ',business.name) as description, 'Subscription' as category, p.reference, business.name as counterparty, r.book_id, b.name as book_name, NULL as to_book_id, NULL as to_book_name, NULL as founder_id, NULL as founder_name, u.name as actor_name, NULL as voided_at, NULL as void_reason, NULL as parent_entry_id");
        return DB::query()->fromSub($manual->unionAll($receipts), 'ledger')->select('ledger.*')->selectSub(DB::table('platform_profit_shares as statement_share')->select('amount_minor')->whereColumn('statement_share.entry_id', 'ledger.id')->where('statement_share.founder_id', $f['founder_id'] ?? '00000000-0000-4000-8000-000000000000'), 'founder_share_minor');
    }
    public function store(Request $r, PlatformLedger $ledger)
    {
        $d = $r->validate(['request_key' => 'required|uuid', 'type' => ['required', Rule::in(PlatformLedger::TYPES)], 'currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/', 'amount' => 'required|string|max:20', 'entry_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'description' => 'required|string|max:500', 'category' => 'nullable|string|max:100', 'reference' => 'nullable|string|max:150', 'counterparty' => 'nullable|string|max:150', 'book_id' => 'nullable|uuid', 'to_book_id' => 'nullable|uuid', 'founder_id' => 'nullable|uuid', 'pay_now' => 'required|boolean', 'settings_version' => 'required|integer|min:0', 'direction' => 'required|in:increase,decrease']);
        if (in_array($d['type'], ['allocation', 'payout']) && $d['entry_date'] !== today()->toDateString()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['entry_date' => 'Record founder allocations and payouts on today’s date.']);
        }
        $ledger->post($d, $r->user());
        return back()->with('success', 'Financial entry recorded. No bank transfer was sent.');
    }
    public function void(Request $r, Entry $entry, PlatformLedger $ledger)
    {
        $d = $r->validate(['reason' => 'required|string|max:500']);
        $ledger->void($entry, $d['reason'], $r->user());
        return back()->with('success', 'Entry reversed. Original history is retained.');
    }
    public function assign(Request $r, string $receipt, PlatformLedger $ledger)
    {
        $d = $r->validate(['book_id' => 'required|uuid']);
        $ledger->assignReceipt($receipt, $d['book_id'], $r->user());
        return back()->with('success', 'Receipt assigned to bank book.');
    }
    public function founders(Request $r, PlatformLedger $ledger)
    {
        $c = $this->currency($r);
        return Spa::render('Platform/Founders', ['configuration' => $ledger->founders(), 'summary' => $ledger->summary($c), 'currencies' => $this->currencies(), 'owner' => $r->user()->platform_role === 'owner']);
    }
    public function saveFounders(Request $r, PlatformLedger $ledger)
    {
        abort_unless($r->user()->platform_role === 'owner', 403);
        $d = $r->validate(['version' => 'required|integer|min:0', 'founders' => 'present|array|max:100', 'founders.*.id' => 'nullable|uuid', 'founders.*.name' => 'required|string|max:150', 'founders.*.share_bps' => 'required|integer|min:0|max:10000', 'founders.*.is_active' => 'required|boolean']);
        $ledger->saveFounders($d, $r->user());
        return back()->with('success', 'Founder shares saved for future allocations.');
    }
    public function books(Request $r, PlatformLedger $ledger)
    {
        $c = $this->currency($r);
        return Spa::render('Platform/BankBooks', ['summary' => $ledger->summary($c), 'currencies' => $this->currencies(), 'owner' => $r->user()->platform_role === 'owner']);
    }
    public function saveBook(Request $r, PlatformLedger $ledger, ?Book $book = null)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/', 'account_reference' => 'nullable|string|max:150', 'is_active' => 'required|boolean', 'is_default' => 'required|boolean']);
        $saved = $ledger->saveBook($book, $d, $r->user());
        return redirect()->route('platform.bank-books.index', ['currency' => $saved->currency])->with('success', 'Bank book saved. Existing receipts keep their original book.');
    }
    public function export(Request $r)
    {
        $c = $this->currency($r);
        $f = $this->filters($r);
        PlatformAudit::record('finance.ledger_exported', $r->user(), null, ['currency' => $c, 'filters' => $f]);
        $rows = $this->rows($c, $f);
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Source', 'Type', 'Currency', 'Amount', 'Bank book', 'Receiving book', 'Founder', 'Description', 'Reference', 'State', 'Void reason'], ',', '"', '');
            foreach ($rows->orderBy('entry_date')->orderBy('created_at')->cursor() as $e) {
                $line = [$e->entry_date, $e->source, $e->type, $e->currency, number_format(($e->founder_share_minor ?? $e->amount_minor) / 100, 2, '.', ''), $e->book_name, $e->to_book_name, $e->founder_name, $e->description, $e->reference, $e->voided_at ? 'voided' : 'posted', $e->void_reason];
                $line = array_map(fn($s) => preg_match('/^[\s]*[=+\-@]/', (string) $s) ? "'" . $s : $s, $line);
                fputcsv($out, $line, ',', '"', '');
            }
            fclose($out);
        }, 'platform-financial-book-' . $c . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
