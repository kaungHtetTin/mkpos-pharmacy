<?php
namespace App\Console\Commands;
use App\Models\Business;
use App\Billing\SubscriptionService;
use Illuminate\Console\Command;
class InitializeSubscriptions extends Command
{
    protected $signature='subscriptions:initialize {--force : Initialize missing subscriptions in production}';
    protected $description='Give businesses without a subscription their initial trial; never reset existing trials';
    public function handle(SubscriptionService $service): int
    {
        if(app()->environment('production') && !$this->option('force')){$this->error('Use --force to explicitly initialize the development deployment.');return self::FAILURE;}
        $count=0;Business::whereDoesntHave('subscription')->chunkById(100,function($businesses)use($service,&$count){foreach($businesses as $business){$service->startTrial($business);$count++;}});
        $this->info($count.' initial subscription(s) created.');return self::SUCCESS;
    }
}
