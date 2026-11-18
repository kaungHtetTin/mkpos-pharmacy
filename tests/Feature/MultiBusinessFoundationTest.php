<?php

namespace Tests\Feature;

use App\Models\{Business, User, Product, Category, Tax, Branch, Role, Setting, PricingRule};
use App\Services\Businesses\ProvisionBusiness;
use App\Tenancy\{BusinessContext, BusinessQuery};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiBusinessFoundationTest extends TestCase
{
    use RefreshDatabase;
    private User $alice;
    private User $bob;
    private Business $a;
    private Business $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice=User::factory()->create(['name'=>'Alice']);
        $this->bob=User::factory()->create(['name'=>'Bob']);
        $this->a=app(ProvisionBusiness::class)->create(['name'=>'Alpha Pharmacy','slug'=>'alpha'],$this->alice);
        $this->b=app(ProvisionBusiness::class)->create(['name'=>'Beta Pharmacy','slug'=>'beta'],$this->bob);
    }
    private function within(Business $business, callable $callback) { return app(BusinessContext::class)->run($business,$callback(...)); }
    private function product(Business $business, string $barcode='SAME'): Product
    {
        return $this->within($business,function () use ($barcode) {
            $category=Category::firstOrCreate(['name'=>'Medicine']);
            return Product::create(['name'=>'Medicine '.$barcode,'barcode'=>$barcode,'category_id'=>$category->id,'tax_id'=>Tax::firstOrFail()->id,'min_stock_level'=>1,'tax_method'=>'Exclusive','status'=>'Active']);
        });
    }
    private function branch(Business $business): string { return DB::table('branches')->where('business_id',$business->id)->value('id'); }
    private function role(Business $business, string $name='Owner'): string { return DB::table('roles')->where('business_id',$business->id)->where('name',$name)->value('id'); }
    private function token(User $user, Business $business): string
    {
        $token=$user->createToken('phase1');$token->accessToken->forceFill(['business_id'=>$business->id])->save();return $token->plainTextToken;
    }

    public function test_provisioning_initializes_separate_roles_settings_and_pricing(): void
    {
        foreach ([$this->a,$this->b] as $business) $this->within($business,function () use ($business) {
            $this->assertSame($business->name,Setting::get('invoice.pharmacy_name'));
            $this->assertSame(4,Role::count());$this->assertSame(2,PricingRule::count());
            $this->assertSame(1,(int)BusinessQuery::table('pricing_states')->value('version'));
        });
        $this->assertSame(0,Product::count());$this->assertSame(0,Role::count());
    }
    public function test_model_and_raw_queries_fail_closed_and_allow_reused_business_codes(): void
    {
        $a=$this->product($this->a);$b=$this->product($this->b);
        $this->assertSame(0,Product::count());
        $this->within($this->a,function () use ($a,$b) {
            $this->assertSame([$a->id],Product::pluck('id')->all());
            $this->assertNull(Product::find($b->id));
            $this->assertSame(1,BusinessQuery::table('products')->count());
        });
        $this->assertNull(app(BusinessContext::class)->id());
    }
    public function test_validation_is_business_scoped_and_cannot_accept_foreign_ids(): void
    {
        $p=$this->product($this->a,'UNIQUE-A');
        $this->within($this->b,function () use ($p) {
            $this->assertTrue(Validator::make(['barcode'=>'UNIQUE-A'],['barcode'=>'unique:products,barcode'])->passes());
            $this->assertTrue(Validator::make(['product_id'=>$p->id],['product_id'=>'exists:products,id'])->fails());
        });
    }
    public function test_model_rejects_cross_business_relation(): void
    {
        $product=$this->product($this->a);
        $category=$this->within($this->b,fn()=>Category::create(['name'=>'Other category']));
        $this->expectException(ValidationException::class);
        $this->within($this->a,fn()=> $product->update(['category_id'=>$category->id]));
    }
    public function test_database_rejects_cross_business_relation_even_without_eloquent(): void
    {
        $product=$this->product($this->a);
        $category=$this->within($this->b,fn()=>Category::create(['name'=>'Other category']));
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('products')->where('id',$product->id)->update(['category_id'=>$category->id]);
    }
    public function test_web_requests_bind_models_and_reports_to_the_assigned_business(): void
    {
        $a=$this->product($this->a,'ALPHA');$b=$this->product($this->b,'BETA');
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])->withHeader('X-SPA','true')
            ->get('/products')->assertOk()->assertJsonPath('props.business.id',$this->a->id)->assertJsonCount(1,'props.products.data')->assertJsonPath('props.products.data.0.id',$a->id);
        $this->get('/products/'.$b->id.'/edit')->assertNotFound();
        $this->get('/reports')->assertOk();
        $this->assertNull(app(BusinessContext::class)->id());
    }
    public function test_stale_browser_tab_is_rejected_before_writes(): void
    {
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])->withHeader('X-Business-ID',$this->b->id)->postJson('/categories',['name'=>'Wrong tab'])->assertStatus(409);
        $this->within($this->a,fn()=> $this->assertSame(0,Category::count()));
    }
    public function test_token_is_business_bound_and_cannot_read_foreign_products(): void
    {
        $a=$this->product($this->a,'ALPHA');$this->product($this->b,'BETA');
        $token=$this->token($this->alice,$this->a);
        $this->withToken($token)->getJson('/api/account/profile')->assertOk()->assertJsonPath('business_id',$this->a->id);
        $this->getJson('/api/cashier/products')->assertOk()->assertJsonFragment(['id'=>$a->id])->assertJsonMissing(['barcode'=>'BETA']);
    }
    public function test_suspended_business_and_inactive_membership_are_blocked(): void
    {
        $token=$this->token($this->alice,$this->a);
        $this->a->update(['status'=>'Suspended']);
        $this->withToken($token)->getJson('/api/account/profile')->assertForbidden();
        $this->a->update(['status'=>'Active']);
        $this->a->memberships()->where('user_id',$this->alice->id)->update(['status'=>'Inactive']);
        $this->getJson('/api/cashier/products')->assertForbidden();
    }
    public function test_an_account_cannot_join_a_second_business_even_when_inactive(): void
    {
        $this->a->memberships()->where('user_id',$this->alice->id)->update(['status'=>'Inactive']);
        $this->expectException(ValidationException::class);
        $this->b->memberships()->create(['user_id'=>$this->alice->id,'status'=>'Active']);
    }

    public function test_only_separate_platform_accounts_can_provision_and_suspend_businesses(): void
    {
        $this->actingAs($this->alice)->get('/platform/businesses')->assertForbidden();
        $admin=User::factory()->create();$admin->forceFill(['is_platform_admin'=>true])->save();
        $this->actingAs($admin)->post('/platform/businesses',['name'=>'Gamma','slug'=>'gamma','owner_name'=>'Gamma Owner','owner_email'=>'gamma-owner@example.test','owner_password'=>'gamma-test-password','currency_symbol'=>'K'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('businesses',['slug'=>'gamma']);
        $this->put('/platform/businesses/'.$this->b->id,['status'=>'Suspended'])->assertRedirect();
        $this->assertSame('Suspended',$this->b->fresh()->status);
        $this->assertDatabaseHas('platform_activity_logs',['action'=>'business.created','actor_id'=>$admin->id]);
        $this->assertDatabaseHas('platform_activity_logs',['action'=>'business.status_changed','business_id'=>$this->b->id]);
        $this->postJson('/platform/businesses',['name'=>'Another','slug'=>'another','owner_name'=>'Alice','owner_email'=>$this->alice->email,'owner_password'=>'gamma-test-password','currency_symbol'=>'K'])->assertUnprocessable()->assertJsonValidationErrors('owner_email');
        $this->assertDatabaseMissing('businesses',['slug'=>'another']);
    }

    public function test_last_owner_cannot_be_removed(): void
    {
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])->putJson('/business-members/'.$this->alice->id,['status'=>'Active','role_id'=>$this->role($this->a,'Cashier'),'branch_id'=>$this->branch($this->a)])->assertStatus(422);
        $this->within($this->a,fn()=> $this->assertTrue($this->alice->hasRole('Owner')));
    }
    public function test_web_login_opens_assigned_business_and_ignores_forged_session_selection(): void
    {
        $this->post('/login',['email'=>$this->alice->email,'password'=>'password'])->assertRedirect(route('dashboard'));
        $this->withSession(['business_id'=>$this->b->id])->withHeader('X-SPA','true')->get('/products')->assertOk()->assertJsonPath('props.business.id',$this->a->id)->assertJsonMissingPath('props.businesses');
    }

    public function test_api_login_automatically_binds_the_accounts_single_business(): void
    {
        $this->postJson('/api/login',['email'=>$this->alice->email,'password'=>'password'])->assertOk()->assertJsonPath('business.id',$this->a->id)->assertJsonMissingPath('businesses');
        $this->assertDatabaseHas('personal_access_tokens',['business_id'=>$this->a->id]);
        $this->postJson('/api/login',['email'=>$this->alice->email,'password'=>'password','business_id'=>$this->b->id])->assertUnprocessable()->assertJsonValidationErrors('business_id');
    }

    public function test_business_switching_and_account_linking_endpoints_are_removed(): void
    {
        $this->withToken($this->token($this->alice,$this->a))->getJson('/api/account/businesses')->assertNotFound();
        $this->postJson('/api/account/businesses/switch',['business_id'=>$this->b->id])->assertNotFound();
        $this->actingAs($this->alice)->get('/businesses')->assertNotFound();
        $this->postJson('/businesses/select',['business_id'=>$this->b->id])->assertNotFound();
        $this->postJson('/business-members',['email'=>$this->bob->email])->assertStatus(405);
    }

    public function test_business_media_is_private_and_signature_expires(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $path=$this->within($this->a,fn()=> \App\Tenancy\BusinessFiles::store(\Illuminate\Http\UploadedFile::fake()->create('medicine.png',1,'image/png'),'products'));
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])->get('/storage/'.$path)->assertOk();
        $this->actingAs($this->bob)->withSession(['business_id'=>$this->b->id])->get('/storage/'.$path)->assertNotFound();
        $url=$this->within($this->a,fn()=> \App\Tenancy\BusinessFiles::url($path));
        $this->within($this->b,fn()=> $this->assertNull(\App\Tenancy\BusinessFiles::url($path)));
        $this->get($url)->assertOk();
        $this->travel(16)->minutes();$this->get($url)->assertForbidden();
    }
    public function test_pricing_state_and_rule_updates_do_not_cross_businesses(): void
    {
        $this->within($this->a,function () { PricingRule::where('code','selling_price')->firstOrFail()->update(['markup_percent'=>25]);BusinessQuery::table('pricing_states')->where('id',1)->increment('version'); });
        $this->within($this->b,function () { $this->assertSame('0.0000',PricingRule::where('code','selling_price')->firstOrFail()->markup_percent);$this->assertSame(1,(int)BusinessQuery::table('pricing_states')->where('id',1)->value('version')); });
    }
    public function test_cross_business_pivot_assignment_is_rejected_by_database(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->within($this->a,fn()=> $this->alice->roles()->attach($this->role($this->b,'Owner')));
    }

    public function test_platform_identity_can_access_profile_without_a_business_membership(): void
    {
        $admin=User::factory()->create();$admin->forceFill(['is_platform_admin'=>true])->save();
        $this->actingAs($admin)->get('/profile')->assertOk();
        $this->get('/platform/businesses')->assertOk();
        $this->get('/products')->assertRedirect(route('platform.dashboard'));
    }

    public function test_cashier_checkout_changes_only_the_token_business_stock_and_sales(): void
    {
        $products=[];
        foreach ([$this->a,$this->b] as $business) {
            $product=$this->product($business);
            $products[$business->id]=$this->within($business,function () use ($business,$product) {
                $branch=$this->branch($business);
                $owner=$business->id===$this->a->id ? $this->alice : $this->bob;
                $unit=\App\Models\Unit::firstOrFail();
                $pu=\App\Models\ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'conversion_factor'=>1,'selling_price'=>100]);
                \App\Models\Inventory::create(['branch_id'=>$branch,'product_id'=>$product->id,'quantity'=>20]);
                \App\Models\InventoryBatch::create(['branch_id'=>$branch,'product_id'=>$product->id,'batch_number'=>'CHECKOUT','quantity'=>20,'purchase_price'=>50,'selling_price'=>100,'expiry_date'=>today()->addYear()]);
                \App\Models\CashSession::create(['branch_id'=>$branch,'user_id'=>$owner->id,'opening_amount'=>0,'opened_at'=>now(),'status'=>'open']);
                return $pu;
            });
        }
        $a=$products[$this->a->id];$b=$products[$this->b->id];
        $payload=['payment_method'=>'Cash','payment_status'=>'Paid','amount_received'=>100,'items'=>[['product_id'=>$a->product_id,'unit_id'=>$a->unit_id,'product_unit_id'=>$a->id,'quantity'=>1,'unit_price'=>100]]];
        $this->withToken($this->token($this->alice,$this->a))->postJson('/api/cashier/sales',$payload)->assertSuccessful();
        $this->assertDatabaseHas('sales',['business_id'=>$this->a->id,'grand_total'=>100]);
        $this->assertSame(0,DB::table('sales')->where('business_id',$this->b->id)->count());
        $this->assertDatabaseHas('inventories',['business_id'=>$this->a->id,'product_id'=>$a->product_id,'quantity'=>19]);
        $this->assertDatabaseHas('inventories',['business_id'=>$this->b->id,'product_id'=>$b->product_id,'quantity'=>20]);
        $payload['items'][0]=['product_id'=>$b->product_id,'unit_id'=>$b->unit_id,'product_unit_id'=>$b->id,'quantity'=>1,'unit_price'=>100];
        $this->postJson('/api/cashier/sales',$payload)->assertUnprocessable();
        $this->assertDatabaseCount('sales',1);
    }

    public function test_tenant_role_management_cannot_create_reserved_privilege_roles(): void
    {
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])
            ->postJson('/roles',['name'=>'ROOT','permissions'=>[]])->assertUnprocessable();
        $this->patchJson('/roles/'.$this->role($this->a,'Cashier'),['name'=>'Root','permissions'=>[]])->assertUnprocessable();
    }

    public function test_platform_accounts_cannot_be_added_to_a_business(): void
    {
        $admin=User::factory()->create();$admin->forceFill(['is_platform_admin'=>true])->save();
        $this->actingAs($this->alice)->postJson('/staff/'.$admin->id,['name'=>'Changed'])->assertNotFound();
        $this->expectException(ValidationException::class);
        $this->a->memberships()->create(['user_id'=>$admin->id,'status'=>'Active']);
    }

    public function test_business_accounts_cannot_be_promoted_to_platform_accounts(): void
    {
        $this->expectException(ValidationException::class);
        $this->alice->forceFill(['is_platform_admin'=>true])->save();
    }

    public function test_database_rejects_a_second_business_assignment(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('business_memberships')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'user_id'=>$this->alice->id,'business_id'=>$this->b->id,'status'=>'Active']);
    }

    public function test_existing_assignment_cannot_be_moved_to_a_different_business(): void
    {
        $this->expectException(ValidationException::class);
        $this->alice->businessMembership()->firstOrFail()->update(['business_id'=>$this->b->id]);
    }

    public function test_provisioning_cannot_reuse_an_owner_from_another_business(): void
    {
        try {
            app(ProvisionBusiness::class)->create(['name'=>'Wrong','slug'=>'wrong'],$this->alice);
            $this->fail('An existing owner was reused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('owner_email',$exception->errors());
        }
        $this->assertDatabaseCount('businesses',2);
    }

    public function test_multiple_branches_and_branch_switching_remain_inside_one_business(): void
    {
        $branch=$this->within($this->a,function () {
            $branch=Branch::create(['name'=>'Second Branch','address'=>'Test','status'=>'Active']);
            $this->alice->branches()->attach($branch->id);
            return $branch;
        });
        $this->withToken($this->token($this->alice,$this->a))->postJson('/api/account/branches/switch',['branch_id'=>$branch->id])->assertSuccessful();
        $this->getJson('/api/account/profile')->assertOk()->assertJsonPath('business_id',$this->a->id)->assertJsonPath('current_branch_id',$branch->id);
        $this->postJson('/api/account/branches/switch',['branch_id'=>$this->branch($this->b)])->assertUnprocessable();
        $this->assertSame(1,$this->alice->memberships()->count());
    }

    public function test_a_token_bound_to_another_business_cannot_override_account_assignment(): void
    {
        $this->withToken($this->token($this->alice,$this->b))->getJson('/api/account/profile')->assertForbidden();
    }


    public function test_sales_page_loads_staff_from_membership_branches_without_cross_business_accounts(): void
    {
        $staff=[];
        foreach ([$this->a,$this->b] as $business) {
            $staff[$business->id]=$this->within($business,function () use ($business) {
                $user=User::factory()->create(['branch_id'=>$this->branch($business)]);
                $user->roles()->attach($this->role($business,'Cashier'));
                return $user;
            });
        }
        $this->actingAs($this->alice)->withHeader('X-SPA','true')->get('/sales')
            ->assertOk()->assertJsonPath('component','Sales/Index')
            ->assertJsonCount(1,'props.salesStaff')
            ->assertJsonPath('props.salesStaff.0.id',$staff[$this->a->id]->id)
            ->assertJsonPath('props.salesStaff.0.branch_id',$this->branch($this->a));
    }

}
