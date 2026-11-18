<?php
namespace App\Models;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
class SubscriptionPeriod extends Model
{
    use HasUuid;
    protected $guarded=['id'];
    protected $casts=['plan_snapshot'=>'array','starts_at'=>'datetime','ends_at'=>'datetime'];
}
