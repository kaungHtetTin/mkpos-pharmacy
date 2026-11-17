<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
class CreatePlatformAdministrator extends Command
{
    protected $signature='platform:admin {email}';
    protected $description='Create or promote a platform office administrator';
    public function handle(): int
    {
        $email=mb_strtolower($this->argument('email'));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $this->error('A valid email is required.'); return self::FAILURE; }
        $user=User::withoutGlobalScopes()->where('email',$email)->first();
        if (!$user) {
            $name=$this->ask('Administrator name');
            $password=$this->secret('Password (at least 12 characters)');
            if (!$name || strlen($password ?? '')<12) { $this->error('Name and a password of at least 12 characters are required.'); return self::FAILURE; }
            $user=User::create(['name'=>$name,'email'=>$email,'password'=>Hash::make($password)]);
        }
        if ($user->memberships()->exists()) { $this->error('Use a separate account for platform office administration. This account belongs to a business.'); return self::FAILURE; }
        $user->forceFill(['is_platform_admin'=>true])->save();
        $this->info('Platform administrator ready. Sign in and open /platform/businesses.');
        return self::SUCCESS;
    }
}
