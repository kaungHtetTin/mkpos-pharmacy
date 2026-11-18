<?php

namespace App\Http\Controllers;

use App\Models\{SupportTicket, SupportMessage, User};
use App\Operations\OfficeAccess;
use App\Support\{Spa, PlatformAudit};
use App\Tenancy\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class SupportController extends Controller
{
    private function office(Request $r): bool
    {
        return $r->is('platform/*');
    }
    private function owner(Request $r): void
    {
        abort_unless($r->user()->hasRole('Owner') || $r->user()->hasRole('Root'), 403);
    }
    private function ticket(Request $r, string $id): SupportTicket
    {
        if (!$this->office($r)) {
            $this->owner($r);
        }
        return SupportTicket::when(!$this->office($r), fn($q) => $q->where('business_id', app(BusinessContext::class)->requireId()))->findOrFail($id);
    }
    public function index(Request $r)
    {
        $office = $this->office($r);
        if (!$office) {
            $this->owner($r);
        }
        $status = $r->validate(['status' => 'nullable|in:open,closed'])['status'] ?? null;
        return Spa::render('Operations/Support', ['office' => $office, 'tickets' => SupportTicket::with(['business:id,name', 'assignee:id,name'])->when(!$office, fn($q) => $q->where('business_id', app(BusinessContext::class)->requireId()))->when($status, fn($q) => $q->where('status', $status))->latest('updated_at')->paginate(20)->withQueryString(), 'filter_status' => $status]);
    }
    public function show(Request $r, string $id)
    {
        $t = $this->ticket($r, $id);
        $t->load(['business:id,name', 'assignee:id,name']);
        return Spa::render('Operations/Support', ['office' => $this->office($r), 'ticket' => $t, 'messages' => $t->messages()->with('author:id,name')->paginate(50)]);
    }
    public function store(Request $r)
    {
        $this->owner($r);
        $d = $r->validate(['subject' => 'required|string|max:150', 'body' => 'required|string|max:5000']);
        $t = DB::transaction(function () use ($r, $d) {
            $t = SupportTicket::create(['business_id' => app(BusinessContext::class)->requireId(), 'opened_by' => $r->user()->id, 'subject' => $d['subject']]);
            $this->message($t, $r, $d['body']);
            PlatformAudit::record('support.opened', $r->user(), $t->business_id, ['ticket_id' => $t->id]);
            return $t;
        });
        return redirect()->route('support.show', $t->id);
    }
    private function message(SupportTicket $t, Request $r, string $body): void
    {
        SupportMessage::create(['ticket_id' => $t->id, 'author_id' => $r->user()->id, 'body' => $body, 'created_at' => now()]);
        $t->touch();
    }
    public function reply(Request $r, string $id)
    {
        $d = $r->validate(['body' => 'required|string|max:5000']);
        DB::transaction(function () use ($r, $id, $d) {
            $t = $this->ticket($r, $id);
            $t = SupportTicket::lockForUpdate()->findOrFail($t->id);
            abort_unless($t->status === 'open', 409);
            $this->message($t, $r, $d['body']);
            PlatformAudit::record('support.replied', $r->user(), $t->business_id, ['ticket_id' => $t->id]);
        });
        return back();
    }
    public function action(Request $r, string $id)
    {
        $d = $r->validate(['action' => 'required|in:claim,request_access,grant,revoke,close,reopen']);
        DB::transaction(function () use ($r, $id, $d) {
            $t = $this->ticket($r, $id);
            $t = SupportTicket::lockForUpdate()->findOrFail($t->id);
            $action = $d['action'];
            $office = $this->office($r);
            if (!in_array($action, ['close', 'reopen'], true)) {
                abort_unless($t->status === 'open', 409);
            }
            switch ($action) {
                case 'claim':
                case 'request_access':
                    abort_unless($office && OfficeAccess::allows($r->user(), 'support'), 403);
                    if ($action === 'claim') {
                        $t->assigned_to = $r->user()->id;
                        $t->access_until = null;
                        $t->granted_by = null;
                    } else {
                        abort_unless($t->assigned_to === $r->user()->id, 403);
                        $t->diagnostics_requested_at = now();
                        $t->access_until = null;
                        $t->granted_by = null;
                    }
                    break;
                case 'grant':
                    abort_if($office, 403);
                    $assignee = User::withoutGlobalScopes()->find($t->assigned_to);
                    if (!$assignee || !OfficeAccess::allows($assignee, 'support') || !$t->diagnostics_requested_at) {
                        throw ValidationException::withMessages(['support' => 'Office staff must claim the ticket and request diagnostics first.']);
                    }
                    $t->access_until = now()->addMinutes(30);
                    $t->granted_by = $r->user()->id;
                    break;
                case 'revoke':
                    abort_if($office, 403);
                    $t->access_until = null;
                    $t->granted_by = null;
                    $t->diagnostics_requested_at = null;
                    break;
                case 'close':
                    $t->status = 'closed';
                    $t->access_until = null;
                    $t->granted_by = null;
                    $t->diagnostics_requested_at = null;
                    break;
                case 'reopen':
                    $t->status = 'open';
                    $t->access_until = null;
                    $t->granted_by = null;
                    $t->diagnostics_requested_at = null;
                    break;
            }
            $t->save();
            PlatformAudit::record('support.' . $action, $r->user(), $t->business_id, ['ticket_id' => $t->id, 'expires_at' => $t->access_until?->toIso8601String()]);
        });
        return back();
    }
    public function diagnostics(Request $r, string $id)
    {
        $t = $this->ticket($r, $id);
        abort_unless($this->office($r) && $t->status === 'open' && $t->assigned_to === $r->user()->id && $t->access_until?->isFuture() && $t->granted_by, 403);
        $counts = [];
        foreach (['branches', 'products', 'inventory_batches', 'sales', 'purchases', 'cash_sessions'] as $table) {
            $counts[$table] = DB::table($table)->where('business_id', $t->business_id)->count();
        }
        PlatformAudit::record('support.diagnostics_read', $r->user(), $t->business_id, ['ticket_id' => $t->id]);
        return response()->json(['business_id' => $t->business_id, 'expires_at' => $t->access_until, 'counts' => $counts]);
    }
}
