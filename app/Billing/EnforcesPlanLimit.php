<?php

namespace App\Billing;

use App\Models\Business;
use App\Tenancy\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait EnforcesPlanLimit
{
    public function save(array $options = [])
    {
        $id = $this->getTable() === 'business_memberships' ? $this->business_id : app(BusinessContext::class)->id();
        if (!$id) return parent::save($options);
        return DB::transaction(function () use ($id, $options) {
            // Keep the lock until the insert/status change has completed, including CSV and API writes.
            $business = Business::whereKey($id)->lockForUpdate()->firstOrFail();
            $access = app(SubscriptionAccess::class);
            $access->assertOperational($business);
            $resource = match ($this->getTable()) { 'branches'=>'branches', 'products'=>'products', 'business_memberships'=>'users' };
            $needsSlot = !$this->exists;
            if ($resource === 'users') $needsSlot = $this->status !== 'Inactive' && (!$this->exists || ($this->isDirty('status') && $this->getRawOriginal('status') === 'Inactive'));
            if ($resource === 'products' && $this->exists) $needsSlot = $this->isDirty('deleted_at') && $this->getRawOriginal('deleted_at') !== null && $this->deleted_at === null;
            if ($needsSlot) {
                $limit = $access->summary($business)['plan']['max_'.$resource] ?? null;
                if ($limit !== null && $access->usage($id)[$resource] >= $limit) {
                    $message='Your plan allows '.$limit.' '.$resource.'. Change your plan or reduce active usage before adding more.';
                    $field=match($resource){'users'=>'email',default=>'name'};
                    throw ValidationException::withMessages(['subscription'=>$message,$field=>$message]);
                }
            }
            return parent::save($options);
        });
    }
}
