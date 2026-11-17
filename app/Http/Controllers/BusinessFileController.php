<?php
namespace App\Http\Controllers;
use App\Models\Business;
use App\Tenancy\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class BusinessFileController extends Controller
{
    public function show(Request $request, string $business, string $path)
    {
        if (!$request->hasValidSignature()) abort_unless(app(BusinessContext::class)->id()===$business,404);
        abort_unless(Business::whereKey($business)->where('status','Active')->exists(),404);
        abort_if(str_contains($path,'..') || str_contains($path,'\\') || str_starts_with($path,'/'),404);
        $file='businesses/'.$business.'/'.$path;
        abort_unless(Storage::disk('local')->exists($file),404);
        return Storage::disk('local')->response($file,null,['Cache-Control'=>'private, max-age=300','X-Content-Type-Options'=>'nosniff']);
    }
}
