<?php

namespace App\Console\Commands;

use App\Models\{BusinessSubscription, SubscriptionPeriod, SubscriptionPlan, SubscriptionInvoice};
use App\Billing\{SubscriptionService, SubscriptionAccess};
use App\Support\PlatformAudit;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    protected $signature='subscriptions:process';
    protected $description='Reconcile subscription states and issue upcoming manual renewal invoices';

    public function handle(SubscriptionService $service, SubscriptionAccess $access): int
    {
        $created=0;
        BusinessSubscription::with('business')->chunkById(100,function($subscriptions) use ($service,&$created) {
            foreach($subscriptions as $subscription) if($service->process($subscription->business)) $created++;
        });
        $this->info('Subscription states reconciled; '.$created.' renewal invoice(s) created.');
        return self::SUCCESS;
    }
}
