<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasUuid;
    protected $guarded = ['id'];
    protected $casts = ['amount_minor'=>'integer','interval_months'=>'integer','grace_days'=>'integer','max_branches'=>'integer','max_users'=>'integer','max_products'=>'integer','features'=>'array','is_active'=>'boolean','is_public'=>'boolean','is_trial'=>'boolean'];

    public function snapshot(): array
    {
        return $this->only(['id','code','name','amount_minor','currency','interval_months','grace_days','max_branches','max_users','max_products','features']);
    }
}
