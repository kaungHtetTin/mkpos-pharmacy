<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $query) {
            $id = app(BusinessContext::class)->id();
            $id ? $query->where($query->getModel()->qualifyColumn('business_id'), $id) : $query->whereRaw('1 = 0');
        });
        static::saving(function ($model) {
            $id = app(BusinessContext::class)->requireId();
            if (($model->business_id && $model->business_id !== $id) || ($model->exists && $model->getRawOriginal('business_id') !== $id)) {
                throw ValidationException::withMessages(['business_id'=>'This record belongs to another business.']);
            }
            $model->business_id = $id;
            foreach (BusinessSchema::REFERENCES as $column => $table) {
                if ($model->isDirty($column) && $model->getAttribute($column) !== null && !DB::table($table)->where('business_id', $id)->where('id', $model->$column)->exists()) {
                    throw ValidationException::withMessages([$column=>'The selected record is not available in this business.']);
                }
            }
            if ($model->getTable()==='returns' && $model->isDirty('reference_id') && $model->reference_id) {
                $table=$model->type==='Supplier' ? 'purchases' : 'sales';
                if (!DB::table($table)->where('business_id',$id)->where('id',$model->reference_id)->exists()) throw ValidationException::withMessages(['reference_id'=>'This transaction is not in the current business.']);
            }
            foreach (['user_id','sale_staff_id','actor_id','approved_by','closed_by_user_id','voided_by_user_id'] as $column) {
                if ($model->isDirty($column) && $model->getAttribute($column) && !DB::table('business_memberships')->where('business_id', $id)->where('user_id', $model->$column)->exists()) {
                    throw ValidationException::withMessages([$column=>'The selected user does not belong to this business.']);
                }
            }
        });
        static::deleting(function ($model) {
            abort_unless($model->business_id === app(BusinessContext::class)->requireId(), 403);
        });
    }

    protected function setKeysForSaveQuery($query)
    {
        return parent::setKeysForSaveQuery($query)->where($this->qualifyColumn('business_id'), app(BusinessContext::class)->requireId());
    }
}
