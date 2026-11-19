<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Finance\PlatformLedger;
use App\Models\SubscriptionPayment;
use App\Support\PlatformAudit;
class SyncPlatformFinance extends Command
{
    protected $signature = 'platform:finance-sync';
    protected $description = 'Reconcile approved subscription receipts missing from the platform financial book';
    public function handle(PlatformLedger $ledger): int
    {
        $n = 0;
        SubscriptionPayment::where('status', 'approved')->whereNotExists(fn($q) => $q->selectRaw('1')->from('platform_finance_receipts')->whereColumn('payment_id', 'subscription_payments.id'))->orderBy('id')->chunk(200, function ($rows) use ($ledger, &$n) {
            foreach ($rows as $payment) {
                if ($ledger->recordReceipt($payment, false)) {
                    $n++;
                }
            }
        });
        if ($n) {
            PlatformAudit::record('finance.receipts_reconciled', null, null, ['receipts' => $n]);
        }
        $this->info('Reconciled ' . $n . ' approved receipts.');
        return self::SUCCESS;
    }
}
