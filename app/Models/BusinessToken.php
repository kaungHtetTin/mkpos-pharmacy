<?php

namespace App\Models;

class BusinessToken extends \Laravel\Sanctum\PersonalAccessToken
{
    protected $table = 'personal_access_tokens';
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'business_id'];
}
