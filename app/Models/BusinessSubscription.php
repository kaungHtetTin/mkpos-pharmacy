<?php
namespace App\Models;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
class BusinessSubscription extends Model
{
    use HasUuid;
    protected $guarded=['id'];
    protected $casts=['plan_snapshot'=>'array','trial_started_at'=>'datetime','trial_ends_at'=>'datetime','auto_renew'=>'boolean','canceled_at'=>'datetime'];
    public function business(){return $this->belongsTo(Business::class);}
    public function plan(){return $this->belongsTo(SubscriptionPlan::class,'plan_id');}
}
