<?php

namespace App\Billing;

use App\Models\{Business, BusinessSubscription, SubscriptionPeriod};
use Illuminate\Support\Facades\DB;

class SubscriptionAccess
{
    private array $cache = [];
    private array $loaded = [];
    public function preload($businesses): void {
        $ids=$businesses->pluck('id');$subscriptions=BusinessSubscription::whereIn('business_id',$ids)->get()->keyBy('business_id');
        $periods=SubscriptionPeriod::whereIn('business_id',$ids)->where('starts_at','<=',now())->orderByDesc('ends_at')->get()->groupBy('business_id');
        $next=SubscriptionPeriod::whereIn('business_id',$ids)->where('starts_at','>',now())->orderBy('starts_at')->get()->groupBy('business_id');
        foreach($ids as $id)$this->loaded[$id]=[$subscriptions->get($id),$periods->get($id)?->first(),$next->get($id)?->first()];
    }

    public function forget(): void { $this->cache = []; $this->loaded = []; }

    public function summary(Business $business): array
    {
        $key = $business->id.':'.now()->getTimestamp();
        if (isset($this->cache[$key])) return $this->cache[$key];
        [$subscription,$period,$next] = $this->loaded[$business->id] ?? [
            BusinessSubscription::where('business_id',$business->id)->first(),
            SubscriptionPeriod::where('business_id',$business->id)->where('starts_at','<=',now())->latest('ends_at')->first(),
            SubscriptionPeriod::where('business_id',$business->id)->where('starts_at','>',now())->orderBy('starts_at')->first(),
        ];
        $plan = $period?->plan_snapshot ?? $subscription?->plan_snapshot ?? [];
        $state = 'unsubscribed'; $until = null; $canOperate = false;
        if ($subscription) {
            if ($period && $period->ends_at->isFuture()) {
                $state = $subscription->auto_renew ? 'active' : 'canceling';
                $until = $period->ends_at->copy();
                if ($subscription->auto_renew) $until->addDays((int)($plan['grace_days']??0));
                if ($next) { $until = $next->ends_at->copy(); if ($subscription->auto_renew) $until->addDays((int)($next->plan_snapshot['grace_days']??0)); }
                $canOperate = true;
            } elseif (!$period && $subscription->trial_started_at?->lte(now()) && $subscription->trial_ends_at?->isFuture()) {
                $state = $subscription->auto_renew ? 'trialing' : 'canceling';
                $until = $subscription->trial_ends_at; $canOperate = true;
            } elseif ($period && $subscription->auto_renew && $period->ends_at->copy()->addDays((int)($plan['grace_days']??0))->isFuture()) {
                $state = 'grace'; $until = $period->ends_at->copy()->addDays((int)($plan['grace_days']??0)); $canOperate = true;
            } else {
                $state = $subscription->auto_renew ? 'expired' : 'canceled';
                $until = $period?->ends_at ?? $subscription->trial_ends_at;
            }
        }
        if ($business->status !== 'Active') { $state = 'suspended'; $canOperate = false; }
        return $this->cache[$key] = [
            'is_complimentary'=>(bool)($plan['is_complimentary']??false),
            'business_id'=>$business->id,'state'=>$state,'can_operate'=>$canOperate,'plan'=>$plan,'access_until'=>$until?->toIso8601String(),
            'period_ends_at'=>$period?->ends_at->toIso8601String(),'trial_ends_at'=>$subscription?->trial_ends_at?->toIso8601String(),
            'auto_renew'=>$subscription?->auto_renew ?? false,'cancel_at_period_end'=>$subscription && !$subscription->auto_renew,
            'next_period'=>$next ? ['starts_at'=>$next->starts_at->toIso8601String(),'ends_at'=>$next->ends_at->toIso8601String(),'plan'=>$next->plan_snapshot] : null,
        ];
    }

    public function assertOperational(Business $business, ?string $feature = null): void
    {
        $summary = $this->summary($business);
        if (!$summary['can_operate']) throw new SubscriptionAccessException($summary);
        if ($feature && !($summary['plan']['features'][$feature]??false)) throw new SubscriptionAccessException($summary, 'feature_unavailable', $feature);
    }

    public function usage(string $businessId): array
    {
        return [
            'branches'=>DB::table('branches')->where('business_id',$businessId)->count(),
            'users'=>DB::table('business_memberships')->where('business_id',$businessId)->where('status','Active')->count(),
            'products'=>DB::table('products')->where('business_id',$businessId)->whereNull('deleted_at')->count(),
        ];
    }
}
