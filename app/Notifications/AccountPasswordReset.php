<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use App\Security\RecoveryMail;
class AccountPasswordReset extends ResetPassword
{
    protected function resetUrl($notifiable)
    {
        return rtrim(config('app.url'), '/') . '/reset-password/' . rawurlencode($this->token) . '?' . http_build_query(['email' => $notifiable->getEmailForPasswordReset()], '', '&', PHP_QUERY_RFC3986);
    }
    public function toMail($notifiable)
    {
        $mail = app(RecoveryMail::class);
        $mailer = $mail->configure();
        if (!$mailer) {
            throw new \RuntimeException('Recovery mail is not configured.');
        }
        $sender = $mail->sender();
        return $this->buildMailMessage($this->resetUrl($notifiable))->mailer($mailer)->from($sender['address'], $sender['name']);
    }
}
