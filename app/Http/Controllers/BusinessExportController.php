<?php

namespace App\Http\Controllers;

use App\Operations\BusinessExport;
use App\Support\{Spa, PlatformAudit};
use App\Tenancy\BusinessContext;
use Illuminate\Http\Request;
class BusinessExportController extends Controller
{
    private function owner(Request $r): void
    {
        abort_unless($r->user()->hasRole('Owner') || $r->user()->hasRole('Root'), 403);
    }
    public function index(Request $r)
    {
        $this->owner($r);
        return Spa::render('Operations/Export');
    }
    public function download(Request $r, BusinessExport $export)
    {
        $this->owner($r);
        $r->validate(['confirmed' => 'required|accepted']);
        $b = app(BusinessContext::class)->business();
        try {
            $path = $export->create($b);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }
        PlatformAudit::record('business.exported', $r->user(), $b->id, ['bytes' => filesize($path)]);
        return response()->download($path, 'business-export-' . now()->format('Ymd-His') . '.zip', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend();
    }
}
