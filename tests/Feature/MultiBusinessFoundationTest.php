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
    public function test_web_requests_bind_models_and_reports_to_the_selected_business(): void
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
    public function test_token_is_business_bound_and_foreign_switch_is_denied(): void
    {
        $a=$this->product($this->a,'ALPHA');$this->product($this->b,'BETA');
        $token=$this->token($this->alice,$this->a);
        $this->withToken($token)->getJson('/api/account/profile')->assertOk()->assertJsonPath('business_id',$this->a->id);
        $this->postJson('/api/account/businesses/switch',['business_id'=>$this->b->id])->assertForbidden();
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
    public function test_multi_membership_has_separate_branch_and_role_context(): void
    {
        $this->a->memberships()->create(['user_id'=>$this->bob->id,'status'=>'Active','branch_id'=>$this->branch($this->a),'active_branch_id'=>$this->branch($this->a)]);
        $this->within($this->a,fn()=> $this->bob->roles()->attach($this->role($this->a,'Cashier')));
        $this->within($this->a,function () { $this->assertTrue($this->bob->hasRole('Cashier'));$this->assertFalse($this->bob->hasRole('Owner'));$this->assertSame($this->branch($this->a),$this->bob->currentBranchId()); });
        $this->within($this->b,function () { $this->assertTrue($this->bob->hasRole('Owner'));$this->assertSame($this->branch($this->b),$this->bob->currentBranchId()); });
        $this->within($this->a,fn()=> $this->bob->roles()->sync([$this->role($this->a,'Manager')]));
        $this->within($this->b,fn()=> $this->assertTrue($this->bob->hasRole('Owner')));
    }
    public function test_only_platform_admin_can_provision_and_suspend_businesses(): void
    {
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])->get('/platform/businesses')->assertForbidden();
        $this->alice->forceFill(['is_platform_admin'=>true])->save();
        $this->post('/platform/businesses',['name'=>'Gamma','slug'=>'gamma','owner_name'=>'Bob','owner_email'=>$this->bob->email,'currency_symbol'=>'K'])->assertRedirect();
        $this->assertDatabaseHas('businesses',['slug'=>'gamma']);
        $this->put('/platform/businesses/'.$this->b->id,['status'=>'Suspended'])->assertRedirect();
        $this->assertSame('Suspended',$this->b->fresh()->status);
        $this->assertDatabaseHas('platform_activity_logs',['action'=>'business.created','actor_id'=>$this->alice->id]);
        $this->assertDatabaseHas('platform_activity_logs',['action'=>'business.status_changed','business_id'=>$this->b->id]);
    }
    public function test_last_owner_cannot_be_removed(): void
    {
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])->putJson('/business-members/'.$this->alice->id,['status'=>'Active','role_id'=>$this->role($this->a,'Cashier'),'branch_id'=>$this->branch($this->a)])->assertStatus(422);
        $this->within($this->a,fn()=> $this->assertTrue($this->alice->hasRole('Owner')));
    }
    public function test_business_switch_redirect_accepts_old_tab_header_but_changes_workspace(): void
    {
        $this->b->memberships()->create(['user_id'=>$this->alice->id,'status'=>'Active','branch_id'=>$this->branch($this->b),'active_branch_id'=>$this->branch($this->b)]);
        $this->within($this->b,fn()=> $this->alice->roles()->attach($this->role($this->b,'Cashier')));
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])->withHeader('X-Business-ID',$this->a->id)->followingRedirects()->post('/businesses/select',['business_id'=>$this->b->id])->assertOk();
        $this->assertSame($this->b->id,session('business_id'));
    }
    public function test_api_login_requires_explicit_choice_for_multiple_businesses(): void
    {
        $this->b->memberships()->create(['user_id'=>$this->alice->id,'status'=>'Active']);
        $this->alice->forceFill(['password'=>bcrypt('phase1-password')])->save();
        $this->postJson('/api/login',['email'=>$this->alice->email,'password'=>'phase1-password'])->assertStatus(409)->assertJsonCount(2,'businesses')->assertJsonMissingPath('token');
        $response=$this->postJson('/api/login',['email'=>$this->alice->email,'password'=>'phase1-password','business_id'=>$this->a->id])->assertOk();
        $this->assertDatabaseHas('personal_access_tokens',['business_id'=>$this->a->id]);
    }
    public function test_switching_one_token_does_not_change_another_devices_business(): void
    {
        $this->b->memberships()->create(['user_id'=>$this->alice->id,'status'=>'Active']);
        $one=$this->token($this->alice,$this->a);$two=$this->token($this->alice,$this->a);
        $this->withToken($one)->postJson('/api/account/businesses/switch',['business_id'=>$this->b->id])->assertOk();
        $this->assertDatabaseHas('personal_access_tokens',['token'=>hash('sha256',explode('|',$one,2)[1]),'business_id'=>$this->b->id]);
        $this->assertDatabaseHas('personal_access_tokens',['token'=>hash('sha256',explode('|',$two,2)[1]),'business_id'=>$this->a->id]);
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
        $this->get('/products')->assertRedirect(route('platform.businesses.index'));
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

    public function test_tenant_staff_manager_cannot_reset_platform_administrator_credentials(): void
    {
        $admin=User::factory()->create();$admin->forceFill(['is_platform_admin'=>true])->save();
        $this->a->memberships()->create(['user_id'=>$admin->id,'status'=>'Active','branch_id'=>$this->branch($this->a),'active_branch_id'=>$this->branch($this->a)]);
        $this->within($this->a,fn()=> $admin->roles()->attach($this->role($this->a,'Cashier')));
        $oldHash=$admin->password;
        $this->actingAs($this->alice)->withSession(['business_id'=>$this->a->id])
            ->postJson('/staff/'.$admin->id,['name'=>$admin->name,'email'=>$admin->email,'phone'=>$admin->phone,'password'=>'different-password','password_confirmation'=>'different-password','branch_id'=>$this->branch($this->a),'role_id'=>$this->role($this->a,'Cashier')])->assertUnprocessable();
        $this->assertSame($oldHash,$admin->fresh()->password);
    }

}
