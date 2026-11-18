<?php
namespace App\Models;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
class SubscriptionPayment extends Model
{
    use HasUuid;
    protected $guarded=['id'];
    protected $hidden=['receipt_path','reference_hash'];
    protected $casts=['amount_minor'=>'integer','payment_date'=>'date','reviewed_at'=>'datetime'];
    public function invoice(){return $this->belongsTo(SubscriptionInvoice::class,'invoice_id');}
    public function business(){return $this->belongsTo(Business::class);}
    public function reviewer(){return $this->belongsTo(User::class,'reviewed_by');}
}
