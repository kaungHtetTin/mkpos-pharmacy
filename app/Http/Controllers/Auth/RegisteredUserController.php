<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Businesses\ProvisionBusiness;
use App\Support\{Spa,PlatformAudit};
use Illuminate\Http\{RedirectResponse,Request};
use Illuminate\Support\Facades\{Auth,DB,Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpFoundation\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Spa::render('Auth/Register',['trial_days'=>config('billing.trial_days')]);
    }

    public function store(Request $request, ProvisionBusiness $provision): RedirectResponse
    {
        foreach (['name','business_name','email'] as $field) {
            if (is_string($request->input($field))) $request->merge([$field=>$field==='email'?mb_strtolower(trim($request->input($field))):trim($request->input($field))]);
        }
        $data=$request->validate([
            'business_name'=>'required|string|max:150',
            'name'=>'required|string|max:150',
            'email'=>'required|string|email:rfc|max:255|unique:users,email',
            'password'=>['required','string','confirmed',Password::min(12),function($attribute,$value,$fail){if(strlen($value)>72)$fail('Use a password no longer than 72 bytes.');}],
            'currency_symbol'=>'required|string|in:$,Ks,฿,€,£',
            'business_id'=>'prohibited','is_platform_admin'=>'prohibited','platform_role'=>'prohibited',
            'roles'=>'prohibited','status'=>'prohibited','plan_id'=>'prohibited','trial_days'=>'prohibited','branch_id'=>'prohibited',
        ]);
        try {
            $user=DB::transaction(function()use($data,$provision){
                $user=User::create(['name'=>$data['name'],'email'=>$data['email'],'password'=>Hash::make($data['password'])]);
                $business=$provision->create([
                    'name'=>$data['business_name'],
                    'slug'=>substr(Str::slug($data['business_name'])?:'business',0,70).'-'.strtolower((string)Str::ulid()),
                    'currency_symbol'=>$data['currency_symbol'],
                ],$user);
                PlatformAudit::record('business.self_registered',$user,$business->id,['owner_id'=>$user->id]);
                return $user->fresh();
            });
        } catch(QueryException $error) {
            if(($error->errorInfo[1]??null)===1062 && str_contains($error->getMessage(),'users_email_unique'))throw ValidationException::withMessages(['email'=>'This email already has an account. Sign in or reset your password.']);
            throw $error;
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(['auth_user_id'=>$request->user()->id,'auth_epoch'=>(int)$request->user()->auth_epoch]);
        return redirect()->route('dashboard')->with('success','Your business is ready. Configure your branch, staff and medicines to get started.');
    }
}
