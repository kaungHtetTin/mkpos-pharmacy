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
        if (static::class === \Tests\Feature\MultiBusinessFoundationTest::class || !array_intersect([RefreshDatabase::class,\Illuminate\Foundation\Testing\DatabaseTransactions::class],class_uses_recursive(static::class))) return;
        $this->fixtureBusiness=Business::create(['name'=>'Regression Test Pharmacy','slug'=>'regression-test','status'=>'Active']);
        app(BusinessContext::class)->set($this->fixtureBusiness);
        DB::table('pricing_states')->insert(['business_id'=>$this->fixtureBusiness->id,'id'=>1,'version'=>1]);
        foreach (['selling_price'=>'Selling price','wholesale_price'=>'Wholesale price'] as $code=>$name) PricingRule::forceCreate(['code'=>$code,'name'=>$name]);
    }
    public function call($method, $uri, $parameters=[], $cookies=[], $files=[], $server=[], $content=null)
    {
        if ($this->fixtureBusiness) app(BusinessContext::class)->set(null);
        try { return parent::call($method,$uri,$parameters,$cookies,$files,$server,$content); }
        finally { if ($this->fixtureBusiness) app(BusinessContext::class)->set($this->fixtureBusiness); }
    }
}
