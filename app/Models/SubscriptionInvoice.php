<?php
namespace App\Models;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
class SubscriptionInvoice extends Model
{
    use HasUuid;
    protected $guarded=['id'];
    protected $casts=['amount_minor'=>'integer','plan_snapshot'=>'array','due_at'=>'datetime','paid_at'=>'datetime','period_starts_at'=>'datetime','period_ends_at'=>'datetime'];
    public function business(){return $this->belongsTo(Business::class);}
    public function payments(){return $this->hasMany(SubscriptionPayment::class,'invoice_id');}
}
