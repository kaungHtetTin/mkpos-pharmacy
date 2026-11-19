<?php
namespace App\Billing;

use App\Models\{Business, PlatformPaymentMethod, SubscriptionInvoice, SubscriptionPayment, SubscriptionPlan, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;

class SubscriptionRequestService
{
    public function submit(Business $business, SubscriptionPlan $plan, User $owner, array $data, UploadedFile $screenshot): SubscriptionPayment
    {
        $created = null;
        try {
            return DB::transaction(function () use ($business, $plan, $owner, $data, $screenshot, &$created) {
                Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
                $existing = SubscriptionPayment::where('business_id', $business->id)->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    if ($existing->invoice->plan_id !== $plan->id || $existing->payment_method_id !== $data['payment_method_id']
                        || $existing->reference !== $this->reference($data)) {
                        throw ValidationException::withMessages(['request_key'=>'This request was already used for different payment details.']);
                    }
                    return $existing;
                }
                $method = PlatformPaymentMethod::where('is_active', true)->lockForUpdate()->find($data['payment_method_id']);
                if (!$method) throw ValidationException::withMessages(['payment_method_id'=>'Choose an available payment method.']);
                $plan = SubscriptionPlan::lockForUpdate()->findOrFail($plan->id);
                $invoice = SubscriptionInvoice::where('business_id', $business->id)->where('status', 'open')->first();
                if ($invoice && $invoice->plan_id !== $plan->id) {
                    throw ValidationException::withMessages(['plan_id'=>'Cancel the existing unpaid request before selecting another plan.']);
                }
                if (!$invoice && (!$plan->is_active || !$plan->is_public || $plan->is_trial || $plan->amount_minor <= 0)) {
                    throw ValidationException::withMessages(['plan_id'=>'Choose an available paid plan. Contact the office for complimentary access.']);
                }
                Money::assertCurrency($invoice?->currency ?? $plan->currency);
                $service = app(SubscriptionService::class);
                $invoice ??= $service->invoice($business, $plan, $data['request_key'], $owner);
                $payment = $service->submit($invoice, $owner, [
                    'request_key'=>$data['request_key'], 'method'=>$method->type ?: 'other',
                    'reference'=>$this->reference($data), 'payment_date'=>$data['payment_date'],
                    'payment_method_id'=>$method->id,
                    'payment_method_snapshot'=>$method->only('name','account_name','account_number','instructions'),
                ], $screenshot);
                if ($payment->wasRecentlyCreated) $created = $payment->receipt_path;
                return $payment;
            });
        } catch (\Throwable $error) {
            if ($created) Storage::disk('local')->delete($created);
            throw $error;
        }
    }

    private function reference(array $data): string
    {
        return trim($data['reference'] ?? '') ?: 'SCREENSHOT-'.$data['request_key'];
    }
}
