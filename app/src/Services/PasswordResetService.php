<?php

namespace App\Src\Services;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use DateTime;
use PDO;

class PasswordResetService
{
    private const TOKEN_EXPIRY = '+15 minutes';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createToken($userId): string
    {
        $this->invalidateActiveTokens($userId);

        $otp = $this->generateOtp();
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $expiresAt = (new DateTime())->modify(self::TOKEN_EXPIRY)->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $otpHash, $expiresAt]);

        return $otp;
    }

    public function validateToken($userId, $otp)
    {
        if (empty($userId) || empty($otp)) {
            return false;
        }

        $record = $this->getLatestActiveToken($userId);
        if (!$record) {
            return false;
        }

        return password_verify($otp, $record['token_hash']) ? $record : false;
    }

    public function markTokenUsed($userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        );
        $statement->execute([$userId]);
    }

    private function invalidateActiveTokens($userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        );
        $statement->execute([$userId]);
    }

    private function generateOtp(): string
    {
        return (string)random_int(100000, 999999);
    }

    private function getLatestActiveToken($userId)
    {
        $statement = $this->db->prepare(
            'SELECT id, token_hash FROM password_resets WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$userId]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }
}
