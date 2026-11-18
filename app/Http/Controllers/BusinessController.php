<?php

namespace App\Http\Controllers;

use App\Models\{Business, User, Role, Branch};
use App\Support\Spa;
use App\Tenancy\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    public function members(Request $request)
    {
        $this->authorizeOwner($request);
        return Spa::render('Businesses/Members', [
            'members'=>User::with(['roles','branches'])->get()->map(fn ($user)=>['id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'status'=>$user->currentMembership()?->status,'roles'=>$user->roles->map->only(['id','name']),'branch_id'=>$user->branch_id]),
            'roles'=>Role::orderBy('name')->get(['id','name']), 'branches'=>Branch::orderBy('name')->get(['id','name']),
        ]);
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
            $member->memberships()->where('business_id',$id)->firstOrFail()->update(['status'=>$data['status'],'branch_id'=>$data['branch_id'],'active_branch_id'=>$data['branch_id']]);
            $member->roles()->sync([$data['role_id']]);
            $member->branches()->syncWithoutDetaching([$data['branch_id']]);
        });
        return redirect()->back()->with('success','Business membership updated.');
    }
    private function authorizeOwner(Request $request): void { abort_unless($request->user()->hasRole('Owner') || $request->user()->hasRole('Root'),403); }
}
