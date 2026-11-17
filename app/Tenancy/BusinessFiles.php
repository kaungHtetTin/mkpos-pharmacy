<?php
namespace App\Tenancy;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage,URL};

final class BusinessFiles
{
    public static function store(UploadedFile $file, string $folder): string
    {
        return $file->store('businesses/'.app(BusinessContext::class)->requireId().'/'.$folder,'local');
    }
    public static function delete(?string $path): void
    {
        if ($path && str_starts_with($path,'businesses/'.app(BusinessContext::class)->requireId().'/')) Storage::disk('local')->delete($path);
    }
    public static function url(?string $path): ?string
    {
        if (!$path) return null;
        $id=app(BusinessContext::class)->requireId();
        if (!str_starts_with($path,'businesses/'.$id.'/')) return null;
        return URL::temporarySignedRoute('business-files.signed',now()->addMinutes(15),['business'=>$id,'path'=>substr($path,strlen('businesses/'.$id.'/'))]);
    }
}
