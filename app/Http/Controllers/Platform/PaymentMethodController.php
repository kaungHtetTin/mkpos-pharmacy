<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformPaymentMethod;
use App\Support\{Spa, PlatformAudit};
use Illuminate\Http\Request;
class PaymentMethodController extends Controller
{
    public function index()
    {
        return Spa::render('Platform/PaymentMethods', ['methods' => PlatformPaymentMethod::orderBy('sort_order')->orderBy('name')->get()]);
    }
    public function store(Request $r)
    {
        return $this->save($r, new PlatformPaymentMethod());
    }
    public function update(Request $r, PlatformPaymentMethod $method)
    {
        return $this->save($r, $method);
    }
    private function save(Request $r, PlatformPaymentMethod $method)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'account_name' => 'nullable|string|max:150', 'account_number' => 'nullable|string|max:150', 'instructions' => 'nullable|string|max:2000', 'is_active' => 'required|boolean', 'sort_order' => 'required|integer|min:0|max:10000']);
        $new = !$method->exists;
        if ($new) $d['type'] = 'other';
        \Illuminate\Support\Facades\DB::transaction(function () use ($r, $method, $d, $new) {
            $method->fill($d)->save();
            PlatformAudit::record($new ? 'billing.payment_method_created' : 'billing.payment_method_updated', $r->user(), null, ['method_id' => $method->id, 'active' => $method->is_active]);
        });
        return back()->with('success', 'Payment method saved.');
    }
}
