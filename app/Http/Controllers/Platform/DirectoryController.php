<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\{Business, BusinessSubscription, SubscriptionPeriod, SubscriptionInvoice, SubscriptionPayment, PlatformActivityLog, SupportTicket, SubscriptionPlan};
use App\Billing\SubscriptionAccess;
use App\Operations\OfficeAccess;
use App\Support\{Spa, PlatformAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};
class DirectoryController extends Controller
{
    public function show(Request $r, Business $business, SubscriptionAccess $access)
    {
        $billing = OfficeAccess::allows($r->user(), 'billing');
        $members = DB::table('business_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')->where('m.business_id', $business->id)
            ->select('u.id', 'u.name', 'u.email', 'u.status', 'm.status as membership_status', 'u.created_at')
            ->orderBy('u.name')->paginate(20, ['*'], 'accounts_page')->withQueryString()->appends(['tab' => 'accounts']);
        $roles = DB::table('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('ru.business_id', $business->id)->whereIn('ru.user_id', $members->pluck('id'))
            ->select('ru.user_id', 'r.name')->get()->groupBy('user_id');
        foreach ($members as $user) $user->roles = ($roles->get($user->id) ?? collect())->pluck('name');

        $invoices = $billing ? SubscriptionInvoice::where('business_id', $business->id)
            ->withCount(['payments', 'payments as pending_payments_count' => fn($q) => $q->where('status', 'pending')])
            ->latest()->paginate(10, ['*'], 'invoices_page')->withQueryString()->appends(['tab' => 'billing']) : null;
        $payments = $billing ? SubscriptionPayment::where('business_id', $business->id)
            ->with(['invoice:id,number,status', 'reviewer:id,name'])->latest()
            ->paginate(10, ['*'], 'payments_page')->withQueryString()->appends(['tab' => 'billing'])
            ->through(fn($payment) => array_merge($payment->toArray(), ['has_receipt' => (bool) $payment->receipt_path])) : null;

        return Spa::render('Platform/BusinessDetail', [
            'record' => $business->only('id', 'name', 'slug', 'status', 'created_at'),
            'subscription' => $access->summary($business),
            'usage' => $access->usage($business->id),
            'accounts_manageable' => OfficeAccess::allows($r->user(), 'users'),
            'members' => $members->items(),
            'members_page' => $members,
            'owner_contacts' => DB::table('business_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.business_id', $business->id)
                ->whereExists(fn($q) => $q->selectRaw('1')->from('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')
                    ->whereColumn('ru.user_id', 'u.id')->where('ru.business_id', $business->id)->whereIn('r.name', ['Owner', 'Root']))
                ->orderBy('u.name')->get(['u.id', 'u.name', 'u.email', 'u.status']),
            'branches' => DB::table('branches')->where('business_id', $business->id)->orderBy('name')->get(['id', 'name', 'phone', 'email', 'address', 'status']),
            'billing_visible' => $billing,
            'plans' => $billing ? SubscriptionPlan::where('is_active', true)->where('is_trial', false)->orderBy('name')->get() : [],
            'invoices_page' => $invoices,
            'payments_page' => $payments,
            'invoices' => $invoices?->items() ?? [],
            'payments' => $payments?->items() ?? [],
            'billing_summary' => $billing ? [
                'open' => SubscriptionInvoice::where('business_id', $business->id)->where('status', 'open')->count(),
                'overdue' => SubscriptionInvoice::where('business_id', $business->id)->where('status', 'open')->where('due_at', '<', now())->count(),
                'pending' => SubscriptionPayment::where('business_id', $business->id)->where('status', 'pending')->count(),
                'collections' => SubscriptionPayment::where('business_id', $business->id)->where('status', 'approved')
                    ->selectRaw('currency, SUM(amount_minor) as amount_minor')->groupBy('currency')->get(),
            ] : null,
            'activity' => PlatformActivityLog::where('business_id', $business->id)
                ->when(!$billing, fn($q) => $q->where('action', 'not like', 'billing.%')->where('action', 'not like', 'finance.%')->where('action', 'not like', 'subscription.%'))
                ->select('id', 'business_id', 'actor_id', 'action', 'created_at')->with('actor:id,name')->latest('created_at')->limit(20)->get(),
            'support' => SupportTicket::where('business_id', $business->id)->latest()->limit(10)->get(['id', 'subject', 'status', 'created_at']),
            'can_extend_trial' => $billing && !SubscriptionPeriod::where('business_id', $business->id)->exists()
                && BusinessSubscription::where('business_id', $business->id)->whereNotNull('trial_ends_at')->exists(),
        ]);
    }
    public function update(Request $r, Business $business)
    {
        abort_unless(OfficeAccess::allows($r->user(), 'users'), 403);
        $d = $r->validate(['name' => 'required|string|max:150', 'slug' => ['required', 'alpha_dash:ascii', 'lowercase', 'max:100', Rule::unique('businesses', 'slug')->ignore($business->id)], 'status' => 'required|in:Active,Suspended']);
        DB::transaction(function () use ($r, $business, $d) {
            $before = $business->only('name', 'slug', 'status');
            $business->update($d);
            PlatformAudit::record('business.profile_updated', $r->user(), $business->id, ['before' => $before, 'after' => $d]);
        });
        return back()->with('success', 'Business details updated.');
    }
    public function extendTrial(Request $r, Business $business)
    {
        abort_unless(OfficeAccess::allows($r->user(), 'billing'), 403);
        $d = $r->validate(['days' => 'required|integer|min:1|max:365', 'note' => 'required|string|max:500']);
        DB::transaction(function () use ($r, $business, $d) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $s = BusinessSubscription::where('business_id', $business->id)->lockForUpdate()->firstOrFail();
            if (!$s->trial_ends_at || SubscriptionPeriod::where('business_id', $business->id)->exists()) {
                throw ValidationException::withMessages(['days' => 'Trial extensions are only available before a business has any paid period.']);
            }
            $before = $s->trial_ends_at->toIso8601String();
            $end = $s->trial_ends_at->isFuture() ? $s->trial_ends_at->copy() : now();
            $end->addDays((int) $d['days']);
            if ($end->gt(now()->addDays(365))) {
                throw ValidationException::withMessages(['days' => 'The extended trial cannot end more than 365 days from today.']);
            }
            $s->update(['trial_ends_at' => $end]);
            PlatformAudit::record('subscription.trial_extended', $r->user(), $business->id, ['previous_end' => $before, 'new_end' => $end->toIso8601String(), 'days' => $d['days'], 'note' => $d['note']]);
        });
        return back()->with('success', 'Trial extended. Business status and paid invoices are unchanged.');
    }
}
