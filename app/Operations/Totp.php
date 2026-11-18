<?php

namespace App\Operations;

final class Totp
{
    public static function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $s = '';
        for ($i = 0; $i < 32; $i++) {
            $s .= $alphabet[random_int(0, 31)];
        }
        return $s;
    }
    public static function code(string $secret, int $counter): string
    {
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $b) {
            if (strlen($b) === 8) {
                $key .= chr(bindec($b));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $n = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }
    public static function counter(string $secret, string $code, int $last = 0): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = intdiv(now()->timestamp, 30);
        foreach ([$now - 1, $now, $now + 1] as $n) {
            if ($n > $last && hash_equals(self::code($secret, $n), $code)) {
                return $n;
            }
        }
        return null;
    }
}
