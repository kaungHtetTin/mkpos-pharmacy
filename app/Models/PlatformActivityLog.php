<?php
namespace App\Models;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
class PlatformActivityLog extends Model
{
    use HasUuid;
    public $timestamps=false;
    protected $fillable=['actor_id','business_id','action','properties','created_at'];
    protected $casts=['properties'=>'array','created_at'=>'datetime'];
    public function actor(){return $this->belongsTo(User::class,'actor_id');}
    public function business(){return $this->belongsTo(Business::class);}
}
