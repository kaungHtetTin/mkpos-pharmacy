<?php

namespace App\Services\Businesses;

use App\Models\{Business, Branch, User, Role, Permission, Setting, Unit, Tax, PricingRule};
use App\Support\PharmacyAccess;
use App\Tenancy\BusinessContext;
use Illuminate\Support\Facades\DB;

final class ProvisionBusiness
{
    public function create(array $data, User $owner): Business
    {
        return DB::transaction(function () use ($data,$owner) {
            $owner = User::withoutGlobalScopes()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if ($owner->is_platform_admin || $owner->memberships()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['owner_email' => 'Each business needs a separate owner account.']);
            }
            $business = Business::create(['name'=>$data['name'],'slug'=>$data['slug'],'status'=>'Active']);
            app(\App\Billing\SubscriptionService::class)->startTrial($business);
            app(BusinessContext::class)->run($business, function () use ($business,$owner,$data) {
                foreach (PharmacyAccess::PERMISSIONS as $definition) Permission::firstOrCreate(['slug'=>$definition['slug']],['name'=>$definition['name']]);
                foreach (PharmacyAccess::ROLES as $name) {
                    $role = Role::create(['name'=>$name]);
                    $slugs = $name === PharmacyAccess::OWNER ? PharmacyAccess::permissionSlugs() : (PharmacyAccess::ROLE_PERMISSIONS[$name] ?? []);
                    $role->permissions()->sync(Permission::whereIn('slug',$slugs)->pluck('id'));
                }
                $business->memberships()->create(['user_id'=>$owner->id,'status'=>'Active']);
                $branch = Branch::create(['name'=>'Main Branch','address'=>$data['address'] ?? '', 'status'=>'Active']);
                $owner->memberships()->where('business_id',$business->id)->update(['branch_id'=>$branch->id,'active_branch_id'=>$branch->id]);
                $owner->roles()->attach(Role::where('name','Owner')->firstOrFail()->id);
                $owner->branches()->attach($branch->id);
                Unit::create(['name'=>'Tablet','short_name'=>'Tab','status'=>'Active']);
                \App\Models\ExpenseCategory::create(['name'=>'General','description'=>'General expenses']);
                Tax::create(['name'=>'Tax Free','rate'=>0,'status'=>true,'is_default'=>true]);
                Setting::set('invoice.pharmacy_name',$business->name);
                Setting::set('app.currency_symbol',$data['currency_symbol'] ?? '$');
                DB::table('pricing_states')->insert(['business_id'=>$business->id,'id'=>1,'version'=>1]);
                foreach (['selling_price'=>'Selling price','wholesale_price'=>'Wholesale price'] as $code=>$name) PricingRule::forceCreate(['code'=>$code,'name'=>$name]);
            });
            return $business;
        });
    }
}
