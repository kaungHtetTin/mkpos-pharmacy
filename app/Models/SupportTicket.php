<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
class SupportTicket extends Model
{
    use HasUuid;
    protected $guarded = ['id'];
    protected $casts = ['access_until' => 'datetime'];
    public function business()
    {
        return $this->belongsTo(Business::class);
    }
    public function messages()
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('created_at')->orderBy('id');
    }
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
