<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Permission;
use App\Support\PharmacyAccess;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PharmacyAccess::PERMISSIONS as $definition) Permission::updateOrCreate(['slug'=>$definition['slug']],['name'=>$definition['name']]);
        $this->command?->info('Permission catalogue ready. Use platform:admin to create your office account, then provision businesses from the platform office.');
    }
}
