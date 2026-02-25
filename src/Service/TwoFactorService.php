<?php

namespace App\Service;

final class TwoFactorService
{
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function buildOtpAuthUri(string $issuer, string $accountLabel, string $secret): string
    {
        $label = rawurlencode($issuer . ':' . $accountLabel);
        $issuerEncoded = rawurlencode($issuer);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            $secret,
            $issuerEncoded
        );
    }

    public function verifyCode(string $secret, string $inputCode, int $window = 1): bool
    {
        $code = preg_replace('/\D+/', '', $inputCode);
        if ($code === null || strlen($code) !== 6) {
            return false;
        }

        $currentCounter = (int) floor(time() / 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $counter = $currentCounter + $offset;
            if (hash_equals($this->generateTotp($secret, $counter), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        $hashes = [];
        foreach ($codes as $code) {
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }

        return $hashes;
    }

    /**
     * @param list<string> $storedHashes
     * @return array{ok: bool, hashes: list<string>}
     */
    public function consumeRecoveryCode(string $inputCode, array $storedHashes): array
    {
        $normalized = strtoupper(trim($inputCode));
        foreach ($storedHashes as $idx => $hash) {
            if (is_string($hash) && password_verify($normalized, $hash)) {
                unset($storedHashes[$idx]);
                return ['ok' => true, 'hashes' => array_values($storedHashes)];
            }
        }

        return ['ok' => false, 'hashes' => array_values($storedHashes)];
    }

    public function encryptSecret(string $plaintext, string $appSecret): string
    {
        $key = hash('sha256', $appSecret, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt 2FA secret.');
        }

        return base64_encode($iv . $cipher);
    }

    public function decryptSecret(string $encrypted, string $appSecret): ?string
    {
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false || strlen($decoded) < 17) {
            return null;
        }

        $key = hash('sha256', $appSecret, true);
        $iv = substr($decoded, 0, 16);
        $cipher = substr($decoded, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    private function generateTotp(string $base32Secret, int $counter): string
    {
        $secret = $this->base32Decode($base32Secret);
        if ($secret === '') {
            return '000000';
        }

        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $secret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );
        $otp = $truncated % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $output = '';
        $length = strlen($binary);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($bits, 5);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $base32): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32) ?? '');
        if ($clean === '') {
            return '';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $length = strlen($clean);
        for ($i = 0; $i < $length; $i++) {
            $index = strpos($alphabet, $clean[$i]);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = str_split($bits, 8);
        $output = '';
        foreach ($bytes as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}

