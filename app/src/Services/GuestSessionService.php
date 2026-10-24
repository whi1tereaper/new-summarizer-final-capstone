<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;
use PDO;

class GuestSessionService
{
    public const GUEST_TTL_SECONDS = 86400;
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function bootstrapCurrentVisitor(): void
    {
        $this->purgeExpiredOccasionally();

        if (!empty($_SESSION['user_id'])) {
            $legacyGuestToken = $this->getCurrentGuestToken();
            unset($_SESSION['guest_token']);
            $this->clearLegacyGuestCookie();
            if ($legacyGuestToken !== null) {
                $this->deleteGuestSessionRecord($legacyGuestToken);
            }
            return;
        }

        $guestToken = $this->getOrCreateGuestToken();
        $_SESSION['guest_token'] = $guestToken;
        $this->touchGuest($guestToken);
    }

    public function getCurrentGuestToken(): ?string
    {
        $guestToken = $_SESSION['guest_token'] ?? null;
        if (!is_string($guestToken) || !$this->isValidGuestToken($guestToken)) {
            return null;
        }

        return $guestToken;
    }

    public function getOrCreateGuestToken(): string
    {
        $guestToken = $this->getCurrentGuestToken();
        if ($guestToken !== null) {
            return $guestToken;
        }

        return bin2hex(random_bytes(32));
    }

    public function claimGuestDataForUser(int $userId, ?string $guestToken): array
    {
        if ($userId < 1 || !$this->isValidGuestToken((string)$guestToken)) {
            return ['summaries' => 0, 'feedback' => 0];
        }

        $this->db->beginTransaction();

        try {
            $summaryStatement = $this->db->prepare(
                'UPDATE summaries
                 SET user_id = :user_id,
                     guest_token = NULL
                 WHERE user_id IS NULL AND guest_token = :guest_token'
            );
            $summaryStatement->execute([
                'user_id' => $userId,
                'guest_token' => $guestToken,
            ]);
            $migratedSummaries = $summaryStatement->rowCount();

            $feedbackStatement = $this->db->prepare(
                'UPDATE feedback SET user_id = :set_user_id, guest_token = NULL
                 WHERE user_id IS NULL AND guest_token = :guest_token
                   AND summary_id IN (SELECT id FROM summaries WHERE user_id = :where_user_id)'
            );
            $feedbackStatement->execute([
                'set_user_id' => $userId,
                'guest_token' => $guestToken,
                'where_user_id' => $userId,
            ]);
            $migratedFeedback = $feedbackStatement->rowCount();

            $this->deleteGuestSessionRecord($guestToken);
            $this->db->commit();

            return [
                'summaries' => $migratedSummaries,
                'feedback' => $migratedFeedback,
            ];
        } catch (\Throwable $throwable) {
            $this->db->rollBack();
            throw $throwable;
        }
    }

    public function clearGuestIdentity(?string $guestToken = null): void
    {
        unset($_SESSION['guest_token']);
        $this->clearLegacyGuestCookie();

        if ($this->isValidGuestToken((string)$guestToken)) {
            $this->deleteGuestSessionRecord($guestToken);
        }
    }

    public function purgeExpiredGuests(): array
    {
        $expiredTokenStatement = $this->db->query(
            'SELECT guest_token FROM guest_sessions WHERE expires_at <= NOW()'
        );
        $expiredTokens = $expiredTokenStatement->fetchAll(PDO::FETCH_COLUMN);

        $this->db->beginTransaction();

        try {
            $deletedFeedback = 0;
            $deletedSummaries = 0;
            $deletedSessions = 0;

            if ($expiredTokens !== []) {
                $placeholders = implode(', ', array_fill(0, count($expiredTokens), '?'));

                $feedbackStatement = $this->db->prepare(
                    "DELETE FROM feedback
                     WHERE user_id IS NULL AND guest_token IN ($placeholders)"
                );
                $feedbackStatement->execute($expiredTokens);
                $deletedFeedback = $feedbackStatement->rowCount();

                $summaryStatement = $this->db->prepare(
                    "DELETE FROM summaries
                     WHERE user_id IS NULL AND guest_token IN ($placeholders)"
                );
                $summaryStatement->execute($expiredTokens);
                $deletedSummaries = $summaryStatement->rowCount();

                $sessionStatement = $this->db->prepare(
                    "DELETE FROM guest_sessions WHERE guest_token IN ($placeholders)"
                );
                $sessionStatement->execute($expiredTokens);
                $deletedSessions = $sessionStatement->rowCount();
            }

            $legacyCutoff = date('Y-m-d H:i:s', time() - self::GUEST_TTL_SECONDS);

            $legacyFeedbackStatement = $this->db->prepare(
                "DELETE FROM feedback
                 WHERE user_id IS NULL
                   AND (guest_token IS NULL OR guest_token = '')
                   AND created_at < ?"
            );
            $legacyFeedbackStatement->execute([$legacyCutoff]);
            $deletedLegacyFeedback = $legacyFeedbackStatement->rowCount();

            $legacySummaryStatement = $this->db->prepare(
                "DELETE FROM summaries
                 WHERE user_id IS NULL
                   AND (guest_token IS NULL OR guest_token = '')
                   AND created_at < ?"
            );
            $legacySummaryStatement->execute([$legacyCutoff]);
            $deletedLegacySummaries = $legacySummaryStatement->rowCount();

            $this->db->commit();

            return [
                'sessions' => $deletedSessions,
                'summaries' => $deletedSummaries,
                'feedback' => $deletedFeedback,
                'legacy_summaries' => $deletedLegacySummaries,
                'legacy_feedback' => $deletedLegacyFeedback,
            ];
        } catch (\Throwable $throwable) {
            $this->db->rollBack();
            throw $throwable;
        }
    }

    private function ensureTable(): void
    {
        // Schema is managed via database/schema.sql
    }

    private function touchGuest(string $guestToken): void
    {
        $dateFormat = 'Y-m-d H:i:s';
        $now = date($dateFormat);
        $expiresAt = date($dateFormat, time() + self::GUEST_TTL_SECONDS);

        $statement = $this->db->prepare(
            'INSERT INTO guest_sessions (guest_token, created_at, last_activity, expires_at)
                 VALUES (:guest_token, :created_at, :last_activity, :expires_at)
                 ON DUPLICATE KEY UPDATE
                    last_activity = VALUES(last_activity),
                    expires_at = VALUES(expires_at)'
        );

        $statement->execute([
            'guest_token' => $guestToken,
            'created_at' => $now,
            'last_activity' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    private function deleteGuestSessionRecord(string $guestToken): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM guest_sessions WHERE guest_token = ?'
        );
        $statement->execute([$guestToken]);
    }

    private function purgeExpiredOccasionally(): void
    {
        if (random_int(1, 100) === 1) {
            $this->purgeExpiredGuests();
        }
    }

    private function clearLegacyGuestCookie(): void
    {
        setcookie('guest_token', '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => $this->requestIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function isValidGuestToken(string $guestToken): bool
    {
        return $guestToken !== '' && preg_match('/^[a-f0-9]{64}$/', $guestToken) === 1;
    }

    private function requestIsHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https') {
            return true;
        }

        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        $cfVisitor = (string)($_SERVER['HTTP_CF_VISITOR'] ?? '');
        return str_contains($cfVisitor, '"scheme":"https"');
    }
}
