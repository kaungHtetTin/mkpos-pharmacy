<?php

namespace App\Security;

use App\Models\PlatformMailSetting;
use Illuminate\Support\Facades\{Mail, Log};
final class RecoveryMail
{
    public function configure(): ?string
    {
        if (app()->environment(['local', 'testing']) && config('mail.default') === 'array') {
            return 'array';
        }
        $s = PlatformMailSetting::find(1);
        if (!$s?->enabled || !$s->username || !$s->password) {
            return null;
        }
        if (app()->environment('production') && parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        config(['mail.mailers.password-recovery' => ['transport' => 'smtp', 'host' => 'smtp.hostinger.com', 'port' => $s->port, 'scheme' => $s->port === 465 ? 'smtps' : 'smtp', 'require_tls' => true, 'username' => $s->username, 'password' => $s->password, 'timeout' => 10, 'local_domain' => parse_url(config('app.url'), PHP_URL_HOST)]]);
        Mail::purge('password-recovery');
        return 'password-recovery';
    }
    public function available(): bool
    {
        try {
            return $this->configure() !== null;
        } catch (\Throwable $error) {
            $this->failed($error);
            return false;
        }
    }
    public function sender(): array
    {
        $s = PlatformMailSetting::find(1);
        return ['address' => $s?->username ?: config('mail.from.address'), 'name' => $s?->from_name ?: config('app.name')];
    }
    public function failed(\Throwable $error): void
    {
        Log::warning('Password recovery email delivery failed.', ['exception_class' => get_class($error)]);
        PlatformMailSetting::whereKey(1)->update(['last_failure_at' => now()]);
    }
    public function sent(): void
    {
        PlatformMailSetting::whereKey(1)->update(['last_sent_at' => now()]);
    }
}
