<?php

namespace App\Http\Controllers;

use App\Models\{Business, BusinessMembership, User, Role, Branch};
use App\Support\Spa;
use App\Tenancy\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        return Spa::render('Businesses/Select', ['businesses'=>$this->available($request->user()), 'selected_business_id'=>$request->session()->get('business_id')]);
    }
    public function available(User $user)
    {
        if ($user->getRawOriginal('status') !== 'Active') return collect();
        return $user->businesses()->wherePivot('status','Active')->where('businesses.status','Active')->orderBy('name')->get(['businesses.id','businesses.name','businesses.slug']);
    }
    public function select(Request $request)
    {
        $data=$request->validate(['business_id'=>'required|uuid']);
        $business=$this->available($request->user())->firstWhere('id',$data['business_id']);
        abort_unless($business,403,'You cannot access that business.');
        \App\Support\PlatformAudit::record('business.selected',$request->user(),$business->id,['channel'=>$request->is('api/*') ? 'api' : 'web']);
        if ($request->is('api/*')) {
            $token=$request->user()->currentAccessToken();
            abort_unless($token instanceof \App\Models\BusinessToken,403);
            $token->forceFill(['business_id'=>$business->id])->save();
            return response()->json(['business'=>$business->only('id','name','slug')]);
        }
        $request->session()->put('business_id',$business->id);
        $request->session()->forget(['locale','errors']);
        return redirect()->route('dashboard');
    }
    public function apiIndex(Request $request) { return response()->json(['businesses'=>$this->available($request->user())]); }

    public function members(Request $request)
    {
        $this->authorizeOwner($request);
        $id=app(BusinessContext::class)->requireId();
        return Spa::render('Businesses/Members', [
            'members'=>User::with(['roles','branches'])->get()->map(fn ($user)=>['id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'status'=>$user->currentMembership()?->status,'roles'=>$user->roles->map->only(['id','name']),'branch_id'=>$user->branch_id]),
            'roles'=>Role::orderBy('name')->get(['id','name']), 'branches'=>Branch::orderBy('name')->get(['id','name']),
        ]);
    }
    public function storeMember(Request $request)
    {
        $this->authorizeOwner($request);
        $data=$request->validate(['email'=>'required|email|max:255','role_id'=>'required|exists:roles,id','branch_id'=>'required|exists:branches,id']);
        $user=User::withoutGlobalScopes()->where('email',mb_strtolower($data['email']))->first();
        abort_unless($user,422,'The account must already exist. New staff can be created in Staff Management.');
        $id=app(BusinessContext::class)->requireId();
        abort_if($user->memberships()->where('business_id',$id)->exists(),422,'This user already has a membership.');
        DB::transaction(function () use ($user,$id,$data) {
            $user->memberships()->create(['business_id'=>$id,'status'=>'Active','branch_id'=>$data['branch_id'],'active_branch_id'=>$data['branch_id']]);
            $user->roles()->attach($data['role_id']);
            $user->branches()->attach($data['branch_id']);
        });
        return redirect()->back()->with('success','Business member added.');
    }
    public function updateMember(Request $request, string $user)
    {
        $this->authorizeOwner($request);
        $member=User::findOrFail($user);
        $data=$request->validate(['status'=>['required',Rule::in(['Active','Inactive'])],'role_id'=>'required|exists:roles,id','branch_id'=>'required|exists:branches,id']);
        $id=app(BusinessContext::class)->requireId();
        DB::transaction(function () use ($member,$request,$data,$id) {
            // Serialize changes to prevent two owners from simultaneously removing the last owner.
            Business::whereKey($id)->lockForUpdate()->firstOrFail();
            $ownerRole=Role::where('name','Owner')->firstOrFail();
            $wasOwner=$member->roles()->whereKey($ownerRole->id)->exists();
            $removesOwner=$data['role_id']!==$ownerRole->id || $data['status']!=='Active';
            if ($wasOwner && $removesOwner) {
                $owners=DB::table('role_user')->join('business_memberships as bm',fn ($join)=>$join->on('bm.user_id','=','role_user.user_id')->on('bm.business_id','=','role_user.business_id'))->where('role_user.business_id',$id)->where('role_user.role_id',$ownerRole->id)->where('bm.status','Active')->count();
                abort_if($owners<=1,422,'A business must keep at least one active owner.');
            }
            abort_if($member->id===$request->user()->id && $data['status']!=='Active',422,'You cannot deactivate your own membership.');
            $member->memberships()->where('business_id',$id)->update(['status'=>$data['status'],'branch_id'=>$data['branch_id'],'active_branch_id'=>$data['branch_id']]);
            $member->roles()->sync([$data['role_id']]);
            $member->branches()->sync([$data['branch_id']]);
        });
        return redirect()->back()->with('success','Business membership updated.');
    }
    private function authorizeOwner(Request $request): void { abort_unless($request->user()->hasRole('Owner') || $request->user()->hasRole('Root'),403); }
}
