<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;
use PDO;

class TermsAcceptanceService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function ensureSchema(): void
    {
        // Schema is managed via database/migrations/008_terms_acceptance.sql
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

    public function currentAuthenticatedUserHasAccepted(): bool
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId) {
            return false;
        }

        $this->ensureSchema();
        $statement = $this->db->prepare(
            'SELECT terms_accepted
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);

        return (int)$statement->fetchColumn() === 1;
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
            'terms.php',
            'accept_terms.php',
            'index.php',
            'login.php',
            'register.php',
            'auth.php',
            'logout.php',
            'nutshell_generate.php',
            'tts_generate.php',
            'tts_audio.php',
            'translate_proxy.php',
            'feedback_submit.php',
            'track_landing.php',
            'check_status.php',
            'summarizer.php',
            'summarize.php',
            'processing.php',
            'result.php',
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

        header('Location: terms.php');
        exit;
    }

    public static function redirectAfterTermsAcceptance(): string
    {
        $requestedTarget = (string)($_SESSION['terms_redirect_after_accept'] ?? '');
        unset($_SESSION['terms_redirect_after_accept']);

        $allowedTargets = ['index.php', 'summarizer.php', 'history.php', 'analytics.php'];

        if (($_SESSION['role'] ?? 'user') === 'admin') {
            if ($requestedTarget === 'admin_dashboard.php' || in_array($requestedTarget, $allowedTargets, true)) {
                return $requestedTarget;
            }
            return 'admin_dashboard.php';
        }

        if (in_array($requestedTarget, $allowedTargets, true)) {
            return $requestedTarget;
        }

        return 'index.php';
    }
}
