<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\{Business, User};
use App\Services\Businesses\ProvisionBusiness;
use App\Support\Spa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\Rules\Password;
class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:Active,Suspended']);
        $search = trim($filters['q'] ?? '');
        $query = Business::when($search !== '', fn($q) => $q->where(fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")->orWhereHas('users', fn($u) => $u->where(fn($u) => $u->where('users.name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))))->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))->withCount('memberships')->with(['users' => fn($q) => $q->limit(3)->select('users.id', 'users.name', 'users.email', 'users.status')->orderBy('users.created_at')])->orderBy('name')->paginate(20)->withQueryString();
        $access = app(\App\Billing\SubscriptionAccess::class);
        $access->preload($query->getCollection());
        $query->through(fn($b) => array_merge($b->only('id', 'name', 'slug', 'status', 'created_at', 'memberships_count'), ['accounts' => $b->users, 'subscription' => $access->summary($b)]));
        return Spa::render('Platform/Businesses', ['businesses' => $query, 'filters' => $filters, 'stats' => ['total' => Business::count(), 'active' => Business::where('status', 'Active')->count(), 'suspended' => Business::where('status', 'Suspended')->count(), 'accounts' => DB::table('business_memberships')->count()]]);
    }
    public function store(Request $request, ProvisionBusiness $provision)
    {
        abort_unless(\App\Operations\OfficeAccess::allows($request->user(), 'users'), 403);
        $data = $request->validate(['name' => 'required|string|max:150', 'slug' => 'required|alpha_dash:ascii|lowercase|max:100|unique:businesses,slug', 'owner_name' => 'required|string|max:255', 'owner_email' => 'required|email|lowercase|max:255|unique:users,email', 'owner_password' => ['required', 'string', Password::min(12)], 'currency_symbol' => 'required|string|max:8']);
        DB::transaction(function () use ($data, $provision, $request) {
            $owner = User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => Hash::make($data['owner_password'])]);
            $business = $provision->create($data, $owner);
            \App\Support\PlatformAudit::record('business.created', $request->user(), $business->id, ['owner_id' => $owner->id]);
        });
        return redirect()->back()->with('success', 'Business created.');
    }
    public function update(Request $request, Business $business)
    {
        abort_unless(\App\Operations\OfficeAccess::allows($request->user(), 'users'), 403);
        $data = $request->validate(['status' => 'required|in:Active,Suspended']);
        DB::transaction(function () use ($business, $data, $request) {
            $previous = $business->status;
            $business->update($data);
            \App\Support\PlatformAudit::record('business.status_changed', $request->user(), $business->id, ['from' => $previous, 'to' => $data['status']]);
        });
        return redirect()->back()->with('success', 'Business status updated.');
    }
}
