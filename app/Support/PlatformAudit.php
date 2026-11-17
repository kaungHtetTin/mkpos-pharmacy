<?php
namespace App\Support;
use App\Models\{PlatformActivityLog,User};
final class PlatformAudit
{
    public static function record(string $action,?User $actor,?string $businessId=null,array $properties=[]): void
    {
        // Callers supply an explicit allowlisted payload, never a request body or credentials.
        PlatformActivityLog::create(['action'=>$action,'actor_id'=>$actor?->id,'business_id'=>$businessId,'properties'=>$properties,'created_at'=>now()]);
    }
}
