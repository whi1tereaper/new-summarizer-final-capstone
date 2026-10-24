<?php
declare(strict_types=1);

namespace App\Src\Repositories;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;
use PDO;
use PDOException;

final class UserRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public function findActiveByCredential(string $usernameOrEmail): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, username, email, password_hash, role, terms_accepted, terms_accepted_at
             FROM users
             WHERE (username = :username OR email = :email) AND active = 1
             LIMIT 1'
        );
        $statement->execute([
            'username' => $usernameOrEmail,
            'email' => $usernameOrEmail,
        ]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            require_once __DIR__.'/../Services/StudentVerificationService.php';
            try {
                $number=\App\Src\Services\StudentVerificationService::normalizeNumber($usernameOrEmail);
                $fingerprint=\App\Src\Services\StudentVerificationService::fingerprint($number);
                $lookup=$this->db->prepare('SELECT u.id,u.username,u.email,u.password_hash,u.role,u.terms_accepted,u.terms_accepted_at FROM users u JOIN student_applications a ON a.user_id=u.id WHERE a.student_number_fingerprint=? AND u.active=1 LIMIT 1');
                $lookup->execute([$fingerprint]);$user=$lookup->fetch(PDO::FETCH_ASSOC);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                // Invalid syntax or unavailable protected key cannot authenticate.
            }
        }
        return is_array($user) ? $user : null;
    }

    public function findActiveById(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, username, email, password_hash, role, terms_accepted, terms_accepted_at
             FROM users
             WHERE id = :id AND active = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    public function findIdByEmail(string $email): ?int
    {
        $statement = $this->db->prepare(
            'SELECT id FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $userId = $statement->fetchColumn();

        return $userId === false ? null : (int)$userId;
    }

    public function findProfileById(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, username, email, created_at
             FROM users
             WHERE id = :id AND active = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    public function findActiveAccountForDeactivation(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, password_hash, role
             FROM users
             WHERE id = :id AND active = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    public function deactivateAccount(int $userId): bool
    {
        $this->db->beginTransaction();

        try {
            $statement = $this->db->prepare(
                'SELECT role, active FROM users WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($user) || (int)$user['active'] !== 1) {
                $this->db->commit();
                return false;
            }

            if ($user['role'] === 'admin') {
                $this->db->commit();
                return false;
            } else {
                $statement = $this->db->prepare(
                    'SELECT active FROM users WHERE id = :id FOR UPDATE'
                );
                $statement->execute(['id' => $userId]);
                if ((int)$statement->fetchColumn() !== 1) {
                    $this->db->commit();
                    return false;
                }
            }

            $statement = $this->db->prepare(
                'UPDATE users SET active = 0 WHERE id = :id AND active = 1'
            );
            $statement->execute(['id' => $userId]);
            if ($statement->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $statement = $this->db->prepare('DELETE FROM user_tokens WHERE user_id = :id');
            $statement->execute(['id' => $userId]);

            $this->db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function createPendingTermsAccount(
        string $username,
        string $email,
        string $passwordHash
    ): array {
        $statement = $this->db->prepare(
            'INSERT INTO users (username, email, password_hash, terms_accepted, terms_accepted_at)
             VALUES (:username, :email, :password_hash, 0, NULL)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'username' => $username,
            'email' => $email,
            'role' => 'user',
            'terms_accepted' => 0,
            'terms_accepted_at' => null,
        ];
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id'
        );
        $statement->execute([
            'password_hash' => $passwordHash,
            'id' => $userId,
        ]);
    }

    public static function isUniqueConstraintViolation(PDOException $exception): bool
    {
        $sqlState = (string)$exception->getCode();
        $driverCode = $exception->errorInfo[1] ?? null;

        return ($sqlState === '23000' && (int)$driverCode === 1062) || $sqlState === '23505';
    }
}
