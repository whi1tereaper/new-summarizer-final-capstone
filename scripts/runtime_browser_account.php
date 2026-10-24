<?php
declare(strict_types=1);
// Local-only fixture lifecycle. No mail is sent and no existing account is used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('LIGHT_JOB_DATABASE_DRIVER=mysql');
require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/src/Utils/PasswordPolicy.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$name = $input['username'] ?? '';
if (!is_string($name) || !preg_match('/\Aqa_runtime_[a-f0-9]{12}\z/', $name)) throw new RuntimeException('Invalid fixture identity.');
$email = $name . '@example.invalid';
$db = \App\Src\Database::getInstance()->getConnection();
if (($argv[1] ?? '') === 'create') {
    $password = $input['password'] ?? '';
    if (!is_string($password) || strlen($password) < 16 || strlen($password) > 72) throw new RuntimeException('Invalid fixture credential.');
    $row = (new \App\Src\Repositories\UserRepository($db))->createPendingTermsAccount(
        $name, $email, \App\Src\Utils\PasswordPolicy::hash($password));
    echo json_encode(['id' => $row['id'], 'username' => $name]);
} elseif (($argv[1] ?? '') === 'delete') {
    $query = $db->prepare('SELECT id FROM users WHERE id=? AND username=? AND email=?');
    $query->execute([(int)($input['id'] ?? 0), $name, $email]);
    $id = $query->fetchColumn();
    if ($id === false) throw new RuntimeException('Fixture ownership validation failed.');
    $db->beginTransaction();
    try {
        // Every deletion is restricted to the identity created by this harness.
        $db->prepare('DELETE FROM summary_jobs WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM summaries WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM users WHERE id=? AND username=? AND email=?')->execute([$id,$name,$email]);
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
    echo json_encode(['deleted_fixture_id' => (int)$id]);
} else { throw new RuntimeException('Unknown fixture operation.'); }
