<?php
namespace Tests\Feature;

use App\Models\{Branch, Inventory, Product, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockFilterTest extends TestCase
{
    use RefreshDatabase;
    private Branch $branch;
    protected function setUp(): void
    {
        parent::setUp();
        $this->branch=Branch::create(['name'=>'Filter branch','phone'=>'000','address'=>'Test','status'=>'Active']);
        $user=User::factory()->create(['branch_id'=>$this->branch->id,'active_branch_id'=>$this->branch->id]);
        $this->grantPermissions($user,['manage_inventory']);$this->actingAs($user);
    }
    private function product(string $name,int $minimum,?int $quantity): Product
    {
        $p=Product::factory()->create(['name'=>$name,'min_stock_level'=>$minimum]);
        if($quantity!==null) Inventory::create(['product_id'=>$p->id,'branch_id'=>$this->branch->id,'quantity'=>$quantity]);
        return $p;
    }
    private function rows(string $status,array $extra=[]): array
    {
        return $this->get('/inventory?'.http_build_query(['stock_status'=>$status]+$extra),['X-SPA'=>'true'])->assertOk()->json('props.inventory.data');
    }
    public function test_in_stock_excludes_zero_and_missing_inventory_with_zero_minimum(): void
    {
        $this->product('Zero minimum zero stock',0,0);$this->product('Zero minimum missing inventory',0,null);
        $positive=$this->product('Positive stock zero minimum',0,1);$atMinimum=$this->product('Stock equals minimum',10,10);
        $this->product('Low stock',10,9);$this->product('Out of stock',10,0);
        $rows=$this->rows('In Stock');$this->assertEqualsCanonicalizing([$positive->id,$atMinimum->id],array_column($rows,'id'));
        foreach($rows as $row){$this->assertGreaterThan(0,$row['current_stock']);$this->assertSame('In Stock',$row['stock_status']);}
    }
    public function test_low_and_out_of_stock_filters_match_the_displayed_status(): void
    {
        $zero=$this->product('Zero',0,0);$missing=$this->product('Missing',0,null);$low=$this->product('Low',10,9);$this->product('Enough',10,10);
        $this->assertEqualsCanonicalizing([$zero->id,$missing->id],array_column($this->rows('Out of Stock'),'id'));
        $rows=$this->rows('Low Stock');$this->assertSame([$low->id],array_column($rows,'id'));$this->assertSame('Low Stock',$rows[0]['stock_status']);
    }
    public function test_stock_filter_uses_selected_branch_quantity_before_pagination(): void
    {
        $other=Branch::create(['name'=>'Filter branch','phone'=>'000','address'=>'Test','status'=>'Active']);$p=$this->product('Stock only in other branch',0,0);
        Inventory::create(['product_id'=>$p->id,'branch_id'=>$other->id,'quantity'=>20]);
        $this->assertSame([],$this->rows('In Stock',['branch_id'=>$this->branch->id]));
        $this->assertSame([$p->id],array_column($this->rows('In Stock',['branch_id'=>$other->id]),'id'));
        $this->assertSame([$p->id],array_column($this->rows('In Stock'),'id'));
    }
    public function test_empty_products_do_not_fill_filtered_pages_or_inflate_totals(): void
    {
        $a=$this->product('Available A',0,3);$b=$this->product('Available B',5,5);
        for($i=0;$i<20;$i++)$this->product('Unavailable '.$i,0,$i%2===0?0:null);
        $response=$this->get('/inventory?stock_status=In+Stock',['X-SPA'=>'true'])->assertOk();
        $response->assertJsonPath('props.inventory.total',2)->assertJsonCount(2,'props.inventory.data');
        $this->assertEqualsCanonicalizing([$a->id,$b->id],array_column($response->json('props.inventory.data'),'id'));
    }
}
