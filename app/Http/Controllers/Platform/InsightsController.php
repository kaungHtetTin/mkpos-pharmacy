<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\{Business, BusinessSubscription, SubscriptionPeriod, SubscriptionPayment, SubscriptionInvoice, User};
use App\Billing\SubscriptionAccess;
use App\Support\{Spa, PlatformAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Schema};
class InsightsController extends Controller
{
    private function dates(Request $r): array
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = Carbon::parse($d['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($d['to'] ?? now()->toDateString())->endOfDay();
        abort_if($from->gt($to) || $from->diffInDays($to) > 366, 422, 'Choose a date range of at most one year.');
        return [$from, $to];
    }
    public function reports(Request $r)
    {
        [$from, $to] = $this->dates($r);
        $payments = SubscriptionPayment::where('status', 'approved')->whereBetween('reviewed_at', [$from, $to]);
        $collections = (clone $payments)->select('currency')->selectRaw('SUM(amount_minor) as amount_minor, COUNT(*) as count')->groupBy('currency')->get();
        $monthly = (clone $payments)->select('currency')->selectRaw("DATE_FORMAT(reviewed_at,'%Y-%m') as month, SUM(amount_minor) as amount_minor")->groupBy('currency', 'month')->orderBy('month')->get();
        $invoices = SubscriptionInvoice::whereBetween('created_at', [$from, $to])->select('currency', 'status')->selectRaw('SUM(amount_minor) as amount_minor, COUNT(*) as count')->groupBy('currency', 'status')->get();
        return Spa::render('Platform/Reports', ['filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'collections' => $collections, 'monthly' => $monthly, 'invoice_totals' => $invoices, 'stats' => ['new_businesses' => Business::whereBetween('created_at', [$from, $to])->count(), 'new_accounts' => User::withoutGlobalScopes()->where('is_platform_admin', false)->whereBetween('created_at', [$from, $to])->count(), 'pending_payments' => SubscriptionPayment::where('status', 'pending')->count(), 'overdue_invoices' => SubscriptionInvoice::where('status', 'open')->where('due_at', '<', now())->count()]]);
    }
    public function export(Request $r)
    {
        [$from, $to] = $this->dates($r);
        PlatformAudit::record('billing.collections_exported', $r->user(), null, ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
        return response()->streamDownload(function () use ($from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Approved on', 'Business', 'Invoice', 'Currency', 'Amount', 'Method', 'Reference'], ',', '"', '');
            SubscriptionPayment::with(['business:id,name', 'invoice:id,number'])->where('status', 'approved')->whereBetween('reviewed_at', [$from, $to])->orderBy('id')->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    $values = [$p->reviewed_at?->toIso8601String(), $p->business?->name, $p->invoice?->number, $p->currency, number_format($p->amount_minor / 100, 2, '.', ''), $p->method, $p->reference];
                    $values = array_map(fn($v) => preg_match('/^[\s]*[=+\-@]/', (string) $v) ? "'" . $v : $v, $values);
                    fputcsv($out, $values, ',', '"', '');
                }
            });
            fclose($out);
        }, 'subscription-collections-' . $from->toDateString() . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
    public function calendar(Request $r, SubscriptionAccess $access)
    {
        $f = $r->validate(['month' => 'nullable|date_format:Y-m', 'q' => 'nullable|string|max:100']);
        $start = Carbon::createFromFormat('!Y-m', $f['month'] ?? now()->format('Y-m'))->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $events = [];
        // Pre-filter businesses with trial/period boundaries near the selected month.
        $ids = BusinessSubscription::whereBetween('trial_ends_at', [$start, $end])->pluck('business_id')->merge(SubscriptionPeriod::whereBetween('ends_at', [$start->copy()->subDays(30), $end])->pluck('business_id'))->unique();
        Business::whereIn('id', $ids)->when($f['q'] ?? null, fn($q, $v) => $q->where('name', 'like', '%' . $v . '%'))->orderBy('id')->chunk(200, function ($rows) use ($access, $start, $end, &$events) {
            $access->preload($rows);
            foreach ($rows as $b) {
                $s = $access->summary($b);
                if (!$s['access_until']) {
                    continue;
                }
                $date = Carbon::parse($s['access_until']);
                if ($date->betweenIncluded($start, $end)) {
                    $events[] = ['business_id' => $b->id, 'business_name' => $b->name, 'date' => $date->toDateString(), 'state' => $s['state'], 'plan' => $s['plan']['name'] ?? 'No plan'];
                }
            }
            $access->forget();
        });
        usort($events, fn($a, $b) => [$a['date'], $a['business_name']] <=> [$b['date'], $b['business_name']]);
        return Spa::render('Platform/Calendar', ['events' => $events, 'filters' => ['month' => $start->format('Y-m'), 'q' => $f['q'] ?? '']]);
    }
    public function health()
    {
        $ok = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $ok = false;
        }
        return Spa::render('Platform/Health', ['health' => ['database' => $ok, 'php' => PHP_VERSION, 'framework' => app()->version(), 'timezone' => config('app.timezone'), 'checked_at' => now()->toIso8601String(), 'queue_driver' => config('queue.default'), 'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0, 'storage_writable' => is_writable(storage_path('framework')), 'backup' => app(\App\Operations\PlatformBackup::class)->status(), 'mail_available' => app(\App\Security\RecoveryMail::class)->available()]]);
    }
}
