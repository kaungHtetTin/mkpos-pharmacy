<?php

namespace App\Console\Commands;

use App\Models\{BusinessSubscription, SubscriptionPlan, SubscriptionPeriod};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizePlatformTrialCurrency extends Command
{
    protected $signature = 'platform:normalize-trial-currency';
    protected $description = 'Label only zero-value system trials as MMK; preserve financial and paid history';

    public function handle(): int
    {
        $count = DB::transaction(function () {
            $plan = SubscriptionPlan::where('code', 'system-trial')->where('is_trial', true)->where('amount_minor', 0)->lockForUpdate()->first();
            if (!$plan) return 0;
            $plan->update(['currency' => 'MMK']);
            $count = 0;
            BusinessSubscription::where('plan_id', $plan->id)->whereNotIn('business_id', SubscriptionPeriod::select('business_id'))
                ->orderBy('id')->lockForUpdate()->chunkById(100, function ($subscriptions) use (&$count) {
                    foreach ($subscriptions as $subscription) {
                        $snapshot = $subscription->plan_snapshot;
                        if ((int) ($snapshot['amount_minor'] ?? -1) !== 0 || ($snapshot['currency'] ?? null) === 'MMK') continue;
                        $snapshot['currency'] = 'MMK';
                        $subscription->update(['plan_snapshot' => $snapshot]);
                        $count++;
                    }
                });
            return $count;
        }, 3);
        $this->info('Zero-value trial snapshots normalized: ' . $count);
        return self::SUCCESS;
    }
}
