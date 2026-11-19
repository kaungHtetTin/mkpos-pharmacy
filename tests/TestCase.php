<?php

namespace Tests;

use App\Models\{Business, PricingRule};
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    private ?Business $fixtureBusiness = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Existing single-workspace regression tests now explicitly run inside a test business.
        if (in_array(static::class, [\Tests\Feature\MultiBusinessFoundationTest::class, \Tests\Feature\SubscriptionBillingTest::class, \Tests\Feature\PlatformOperationsTest::class, \Tests\Feature\Auth\RegistrationTest::class, \Tests\Feature\Auth\PasswordResetTest::class, \Tests\Feature\PlatformMailTest::class, \Tests\Feature\OfficeFeatureExpansionTest::class, \Tests\Feature\FounderFinanceTest::class, \Tests\Feature\UnifiedBusinessOfficeTest::class], true) || !array_intersect([RefreshDatabase::class,\Illuminate\Foundation\Testing\DatabaseTransactions::class],class_uses_recursive(static::class))) return;
        $this->fixtureBusiness=Business::create(['name'=>'Regression Test Pharmacy','slug'=>'regression-test','status'=>'Active']);
        app(\App\Billing\SubscriptionService::class)->startTrial($this->fixtureBusiness)->update(['trial_started_at'=>now()->subYear(),'trial_ends_at'=>now()->addYear()]);
        app(BusinessContext::class)->set($this->fixtureBusiness);
        DB::table('pricing_states')->insert(['business_id'=>$this->fixtureBusiness->id,'id'=>1,'version'=>1]);
        foreach (['selling_price'=>'Selling price','wholesale_price'=>'Wholesale price'] as $code=>$name) PricingRule::forceCreate(['code'=>$code,'name'=>$name]);
    }
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        $this->withSession(['auth_user_id'=>$user->getAuthIdentifier(),'auth_epoch'=>(int)$user->auth_epoch]);
        return parent::actingAs($user,$guard);
    }

    protected function grantPermissions(\App\Models\User $user, array $slugs): void
    {
        $role = \App\Models\Role::create(['name' => 'Test access '.\Illuminate\Support\Str::uuid()]);
        foreach ($slugs as $slug) {
            $permission = \App\Models\Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
    }

    protected function businessToken(\App\Models\User $user, string $name = 'test-device'): string
    {
        $token = $user->createToken($name);
        $token->accessToken->forceFill(['business_id' => app(BusinessContext::class)->requireId()])->save();
        return $token->plainTextToken;
    }

    public function call($method, $uri, $parameters=[], $cookies=[], $files=[], $server=[], $content=null)
    {
        if ($this->fixtureBusiness) app(BusinessContext::class)->set(null);
        try { return parent::call($method,$uri,$parameters,$cookies,$files,$server,$content); }
        finally { if ($this->fixtureBusiness) app(BusinessContext::class)->set($this->fixtureBusiness); }
    }
}
