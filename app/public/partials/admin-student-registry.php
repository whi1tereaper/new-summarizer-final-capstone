<?php
require_once __DIR__.'/../../src/Services/StudentEnrollmentRegistryService.php';
$registryStats=[];$registryError='';
try{$registryStats=(new App\Src\Services\StudentEnrollmentRegistryService())->stats((int)($_SESSION['user_id']??0));}catch(Throwable $e){$registryError='Apply the additive registry migrations through 020 before using Student ID Management.';}
?>
<section class="student-access admin-section registry-manager" id="student-enrollment-registry"><h2>Student ID Management</h2><p>The active administrator-managed database is the only student eligibility authority. Imported, unclaimed IDs approve registrations immediately—no secondary verification.</p><?php require __DIR__.'/registry-statistics.php'; ?><?php if($registryError): ?><p role="status"><?= htmlspecialchars($registryError,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?><a class="student-button" href="admin_student_registry.php">Manage Student IDs</a></section>
