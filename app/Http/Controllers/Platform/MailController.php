<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformMailSetting;
use App\Security\RecoveryMail;
use App\Support\{Spa, PlatformAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Mail, DB};
use Illuminate\Mail\Message;
use Illuminate\Validation\ValidationException;
class MailController extends Controller
{
    public function index()
    {
        $s = PlatformMailSetting::findOrFail(1);
        return Spa::render('Platform/Email', ['mail_settings' => array_merge($s->only('enabled', 'username', 'port', 'from_name', 'last_tested_at', 'last_test_status', 'last_failure_at', 'last_sent_at'), ['has_password' => (bool) $s->getRawOriginal('password')])]);
    }
    public function update(Request $r)
    {
        if (is_string($r->username)) {
            $r->merge(['username' => mb_strtolower(trim($r->username))]);
        }
        $d = $r->validate(['enabled' => 'required|boolean', 'username' => 'required|string|email:rfc|max:255', 'password' => 'nullable|string|max:500', 'port' => 'required|integer|in:465,587', 'from_name' => ['required', 'string', 'max:150', 'not_regex:/[\r\n]/']]);
        DB::transaction(function () use ($r, $d) {
            $s = PlatformMailSetting::lockForUpdate()->findOrFail(1);
            $changed = $s->username !== $d['username'];
            if ($d['enabled'] && !$r->filled('password') && ($changed || !$s->password)) {
                throw ValidationException::withMessages(['password' => 'Enter the Hostinger mailbox password to enable delivery.']);
            }
            $values = ['enabled' => $d['enabled'], 'username' => $d['username'], 'port' => $d['port'], 'from_name' => $d['from_name'], 'last_test_status' => null, 'last_tested_at' => null];
            if ($r->filled('password')) {
                $values['password'] = $d['password'];
            } elseif ($changed) {
                $values['password'] = null;
            }
            $s->fill($values)->save();
            PlatformAudit::record('mail.settings_updated', $r->user(), null, ['enabled' => (bool) $s->enabled, 'port' => $s->port]);
        });
        return back()->with('success', 'Hostinger email settings saved. Send a test email to verify delivery.');
    }
    public function test(Request $r, RecoveryMail $mail)
    {
        $d = $r->validate(['recipient' => 'required|string|email:rfc|max:255']);
        $mailer = PlatformMailSetting::findOrFail(1)->enabled ? $mail->configure() : null;
        if (!$mailer) {
            return back()->withErrors(['recipient' => 'Enable and save the Hostinger sender first.']);
        }
        try {
            $from = $mail->sender();
            Mail::mailer($mailer)->raw('This is a Pharmacy POS delivery test. No password reset was requested or performed. If you received this message, the configured sender can deliver email.', function (Message $message) use ($d, $from) {
                $message->to($d['recipient'])->from($from['address'], $from['name'])->subject('Pharmacy POS — email delivery test');
            });
            PlatformMailSetting::whereKey(1)->update(['last_tested_at' => now(), 'last_test_status' => 'sent']);
            $mail->sent();
            PlatformAudit::record('mail.test_sent', $r->user());
            return back()->with('success', 'The SMTP server accepted the test email. Check the inbox and spam folder to confirm delivery.');
        } catch (\Throwable $error) {
            $mail->failed($error);
            PlatformMailSetting::whereKey(1)->update(['last_tested_at' => now(), 'last_test_status' => 'failed']);
            return back()->withErrors(['recipient' => 'The SMTP server could not send the message. Check the mailbox credentials and try again.']);
        }
    }
}
