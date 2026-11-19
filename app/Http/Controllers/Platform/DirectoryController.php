<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\{Business, BusinessSubscription, SubscriptionPeriod, SubscriptionInvoice, SubscriptionPayment, PlatformActivityLog, SupportTicket};
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
        $members = DB::table('business_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.business_id', $business->id)->select('u.id', 'u.name', 'u.email', 'u.status', 'm.status as membership_status', 'u.created_at')->orderBy('u.name')->limit(100)->get();
        $roles = DB::table('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')->where('ru.business_id', $business->id)->select('ru.user_id', 'r.name')->get()->groupBy('user_id');
        foreach ($members as $u) {
            $u->roles = ($roles->get($u->id) ?? collect())->pluck('name');
        }
        return Spa::render('Platform/BusinessDetail', ['record' => $business->only('id', 'name', 'slug', 'status', 'created_at'), 'subscription' => $access->summary($business), 'usage' => $access->usage($business->id), 'members' => $members, 'branches' => DB::table('branches')->where('business_id', $business->id)->orderBy('name')->get(['id', 'name', 'phone', 'email', 'address', 'status']), 'billing_visible' => $billing, 'invoices' => $billing ? SubscriptionInvoice::where('business_id', $business->id)->latest()->limit(10)->get() : [], 'payments' => $billing ? SubscriptionPayment::where('business_id', $business->id)->latest()->limit(10)->get(['id', 'amount_minor', 'currency', 'status', 'method', 'reference', 'created_at']) : [], 'activity' => PlatformActivityLog::where('business_id', $business->id)->with('actor:id,name')->latest('created_at')->limit(20)->get(), 'support' => SupportTicket::where('business_id', $business->id)->latest()->limit(10)->get(['id', 'subject', 'status', 'created_at']), 'can_extend_trial' => !SubscriptionPeriod::where('business_id', $business->id)->exists() && BusinessSubscription::where('business_id', $business->id)->whereNotNull('trial_ends_at')->exists()]);
    }
    public function update(Request $r, Business $business)
    {
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
