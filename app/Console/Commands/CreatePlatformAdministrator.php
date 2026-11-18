<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
class CreatePlatformAdministrator extends Command
{
    protected $signature='platform:admin {email} {--role=owner : owner, admin, billing or support} {--reset-mfa : Clear lost MFA enrollment} {--force : Confirm MFA reset}';
    protected $description='Create or promote a platform office administrator';
    public function handle(): int
    {
        $role=$this->option('role');if(!in_array($role,['owner','admin','billing','support'],true)){ $this->error('Invalid office role.');return self::FAILURE; }
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
        if($this->option('reset-mfa')&&!$this->option('force')){$this->error('MFA reset requires --force and trusted server access.');return self::FAILURE;}
        $values=['is_platform_admin'=>true,'platform_role'=>$role];if($this->option('reset-mfa'))$values+=['office_mfa_secret'=>null,'office_recovery_codes'=>null,'office_mfa_counter'=>0];$user->forceFill($values)->save();\App\Support\PlatformAudit::record($this->option('reset-mfa')?'office.mfa_reset':'office.role_set',null,null,['user_id'=>$user->id,'role'=>$role]);
        $this->info('Platform administrator ready. Sign in and open /platform/businesses.');
        return self::SUCCESS;
    }
}
