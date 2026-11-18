<?php

namespace App\Billing;

use App\Support\Spa;
use Illuminate\Http\Request;
use RuntimeException;

class SubscriptionAccessException extends RuntimeException
{
    public function __construct(public array $subscription, public string $reason = 'subscription_required', public ?string $feature = null)
    {
        parent::__construct($reason === 'feature_unavailable' ? 'This feature is not included in your business plan.' : 'Your business subscription needs renewal. Contact your business owner or the platform office.');
    }

    public function render(Request $request)
    {
        $status = $this->reason === 'feature_unavailable' ? 403 : 402;
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message'=>$this->getMessage(),'code'=>$this->reason,'feature'=>$this->feature,'subscription'=>$this->subscription], $status);
        }
        // Middleware has unwound its tenant context by the time Laravel renders an exception.
        $owner = $request->user() && \Illuminate\Support\Facades\DB::table('role_user')->join('roles','roles.id','=','role_user.role_id')
            ->where('role_user.business_id',$this->subscription['business_id'])->where('role_user.user_id',$request->user()->id)->whereIn('roles.name',['Owner','Root'])->exists();
        if ($owner) return redirect()->route('billing.index')->with('error',$this->getMessage());
        return Spa::render('Billing/Unavailable', ['message'=>$this->getMessage(),'subscription'=>$this->subscription])->setStatusCode($status);
    }
}
