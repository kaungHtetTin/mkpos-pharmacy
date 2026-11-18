<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\{Business, BusinessSubscription, SubscriptionPayment, SubscriptionInvoice, SupportTicket, PlatformActivityLog};
use App\Billing\SubscriptionAccess;
use App\Operations\OfficeAccess;
use App\Support\Spa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class DashboardController extends Controller
{
    public function index(Request $r, SubscriptionAccess $access)
    {
        $filter = $r->validate(['q' => 'nullable|string|max:100']);
        $q = trim($filter['q'] ?? '');
        $states = [];
        Business::orderBy('id')->chunk(200, function ($businesses) use (&$states, $access) {
            $access->preload($businesses);
            foreach ($businesses as $b) {
                $s = $access->summary($b)['state'];
                $states[$s] = ($states[$s] ?? 0) + 1;
            }
        });
        $access->forget();
        $businesses = Business::when($q !== '', fn($b) => $b->where(fn($b) => $b->where('name', 'like', '%' . $q . '%')->orWhere('slug', 'like', '%' . $q . '%')))->orderBy('name')->paginate(20)->withQueryString();
        $access->preload($businesses->getCollection());
        $businesses->through(fn($b) => ['id' => $b->id, 'name' => $b->name, 'status' => $b->status, 'subscription' => $access->summary($b), 'usage' => $access->usage($b->id)]);
        $billing = OfficeAccess::allows($r->user(), 'billing');
        $collections = $billing ? SubscriptionPayment::where('status', 'approved')->select('currency')->selectRaw('SUM(amount_minor) as amount_minor, COUNT(*) as payments')->groupBy('currency')->get() : [];
        $monthly = $billing ? SubscriptionPayment::where('status', 'approved')->where('reviewed_at', '>=', now()->startOfMonth()->subMonths(5))->select('currency')->selectRaw("DATE_FORMAT(reviewed_at,'%Y-%m') as month, SUM(amount_minor) as amount_minor")->groupBy('currency', 'month')->orderBy('month')->get() : [];
        return Spa::render('Platform/Dashboard', ['states' => $states, 'businesses' => $businesses, 'query' => $q, 'totals' => ['businesses' => Business::count(), 'open_support' => SupportTicket::where('status', 'open')->count(), 'pending_payments' => $billing ? SubscriptionPayment::where('status', 'pending')->count() : null, 'overdue_invoices' => $billing ? SubscriptionInvoice::where('status', 'open')->where('due_at', '<', now())->count() : null], 'collections' => $collections, 'monthly' => $monthly, 'billing_visible' => $billing, 'backup' => app(\App\Operations\PlatformBackup::class)->status()]);
    }
    public function audit(Request $r)
    {
        return Spa::render('Platform/Audit', ['entries' => PlatformActivityLog::with(['actor:id,name', 'business:id,name'])->latest('created_at')->paginate(50)]);
    }
}
