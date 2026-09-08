<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;
use PDO;

class TermsAcceptanceService
{
    private static bool $schemaVerified = false;
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function ensureSchema(): void
    {
        if (self::$schemaVerified) {
            return;
        }

        if (!$this->columnExists('terms_accepted')) {
            $stmt = $this->db->prepare(
                'ALTER TABLE users
                 ADD COLUMN terms_accepted TINYINT(1) NOT NULL DEFAULT 0
                 AFTER active'
            );
            $stmt->execute();
        }

        if (!$this->columnExists('terms_accepted_at')) {
            $stmt = $this->db->prepare(
                'ALTER TABLE users
                 ADD COLUMN terms_accepted_at DATETIME NULL
                 AFTER terms_accepted'
            );
            $stmt->execute();
        }

        $this->backfillLegacyUsersAsAccepted();

        self::$schemaVerified = true;
    }

    public function bootstrapCurrentUserSession(): void
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId) {
            return;
        }

        $this->ensureSchema();

        if (
            array_key_exists('terms_accepted', $_SESSION)
            && array_key_exists('terms_accepted_at', $_SESSION)
        ) {
            return;
        }

        $statement = $this->db->prepare(
            'SELECT terms_accepted, terms_accepted_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return;
        }

        $this->storeTermsStateInSession($user);
    }

    public function storeTermsStateInSession(array $user): void
    {
        $_SESSION['terms_accepted'] = (int)($user['terms_accepted'] ?? 0);
        $_SESSION['terms_accepted_at'] = isset($user['terms_accepted_at']) && $user['terms_accepted_at'] !== null
            ? (string)$user['terms_accepted_at']
            : null;
    }

    public function acceptCurrentUserTerms(): bool
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId) {
            return false;
        }

        $this->ensureSchema();

        $statement = $this->db->prepare(
            'UPDATE users
             SET terms_accepted = 1,
                 terms_accepted_at = NOW()
             WHERE id = :id'
        );
        $saved = $statement->execute(['id' => $userId]);
        if (!$saved) {
            return false;
        }

        $_SESSION['terms_accepted'] = 1;
        $_SESSION['terms_accepted_at'] = date('Y-m-d H:i:s');

        return true;
    }

    public static function currentUserNeedsAcceptance(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        return (int)($_SESSION['terms_accepted'] ?? 0) !== 1;
    }

    public static function redirectAuthenticatedUserIfTermsPending(): void
    {
        if (!self::currentUserNeedsAcceptance()) {
            return;
        }

        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $action = (string)($_GET['action'] ?? '');
        $allowedScripts = [
            'accept_terms.php',
            'summarizer.php',
            'terms.php',
            'logout.php',
            'nutshell_generate.php',
            'tts_generate.php',
            'tts_audio.php',
            'translate_proxy.php',
            'feedback_submit.php',
            'check_status.php',
        ];

        if (in_array($script, $allowedScripts, true)) {
            return;
        }

        if ($script === 'auth.php' && $action === 'logout') {
            return;
        }

        if ($script !== '') {
            $_SESSION['terms_redirect_after_accept'] = $script;
        }

        header('Location: accept_terms.php');
        exit;
    }

    public static function redirectAfterTermsAcceptance(): string
    {
        $requestedTarget = (string)($_SESSION['terms_redirect_after_accept'] ?? '');
        unset($_SESSION['terms_redirect_after_accept']);

        if ($requestedTarget === 'admin_dashboard.php' && (($_SESSION['role'] ?? 'user') === 'admin')) {
            return 'admin_dashboard.php';
        }

        if (($_SESSION['role'] ?? 'user') === 'admin') {
            return 'admin_dashboard.php';
        }

        return 'index.php';
    }

    private function columnExists(string $columnName): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS matches
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'table_name' => 'users',
            'column_name' => $columnName,
        ]);

        return (int)$statement->fetchColumn() > 0;
    }

    private function backfillLegacyUsersAsAccepted(): void
    {
        // ASSUMPTION: any account still at 0/NULL after this feature was introduced
        // is a legacy record created before the Terms checkbox existed.
        $statement = $this->db->prepare(
            'UPDATE users
             SET terms_accepted = 1,
                 terms_accepted_at = COALESCE(terms_accepted_at, created_at, NOW())
             WHERE COALESCE(terms_accepted, 0) = 0
               AND terms_accepted_at IS NULL
               AND created_at < "2026-05-05 00:00:00"'
        );
        $statement->execute();
    }
}
