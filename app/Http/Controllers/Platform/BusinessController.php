<?php
namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\{Business, User};
use App\Services\Businesses\ProvisionBusiness;
use App\Support\Spa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Validation\Rules\Password;

class BusinessController extends Controller
{
    public function index() { return Spa::render('Platform/Businesses', ['recent_activity'=>\App\Models\PlatformActivityLog::with(['actor:id,name','business:id,name'])->latest('created_at')->limit(20)->get(), 'businesses'=>Business::withCount(['memberships'])->orderBy('name')->get(['id','name','slug','status','created_at'])]); }
    public function store(Request $request, ProvisionBusiness $provision)
    {
        $data=$request->validate(['name'=>'required|string|max:150','slug'=>'required|alpha_dash:ascii|lowercase|max:100|unique:businesses,slug','owner_name'=>'required|string|max:255','owner_email'=>'required|email|lowercase|max:255|unique:users,email','owner_password'=>['required','string',Password::min(12)],'currency_symbol'=>'required|string|max:8']);
        DB::transaction(function () use ($data,$provision,$request) {
            $owner=User::create(['name'=>$data['owner_name'],'email'=>$data['owner_email'],'password'=>Hash::make($data['owner_password'])]);
            $business=$provision->create($data,$owner);
            \App\Support\PlatformAudit::record('business.created',$request->user(),$business->id,['owner_id'=>$owner->id]);
        });
        return redirect()->back()->with('success','Business created.');
    }
    public function update(Request $request, Business $business)
    {
        $data=$request->validate(['status'=>'required|in:Active,Suspended']);
        DB::transaction(function()use($business,$data,$request){ $previous=$business->status;$business->update($data);\App\Support\PlatformAudit::record('business.status_changed',$request->user(),$business->id,['from'=>$previous,'to'=>$data['status']]); });
        return redirect()->back()->with('success','Business status updated.');
    }
}
