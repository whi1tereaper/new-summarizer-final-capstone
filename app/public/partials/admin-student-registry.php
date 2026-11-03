<?php
require_once __DIR__.'/../../src/Services/StudentEnrollmentRegistryService.php';
$registryStats=[];$registryError='';
try{$registryStats=(new App\Src\Services\StudentEnrollmentRegistryService())->stats((int)($_SESSION['user_id']??0));}catch(Throwable $e){$registryError='Apply the additive registry migrations through 020 before using Student ID Management.';}
?>
<section class="student-access registry-manager registry-overview" id="student-enrollment-registry" aria-labelledby="registry-overview-title">
    <div class="registry-overview__header">
        <div class="registry-overview__intro">
            <span class="registry-overview__eyebrow">Student access</span>
            <h2 id="registry-overview-title">Student ID Management</h2>
            <p>Active, unclaimed Student IDs enable immediate registration, with no additional approval step.</p>
        </div>
    </div>
    <?php require __DIR__.'/registry-statistics.php'; ?>
    <?php if($registryError): ?>
        <p class="student-alert" role="status"><?= htmlspecialchars($registryError,ENT_QUOTES,'UTF-8') ?></p>
    <?php else: ?>
        <p class="registry-overview__note">Eligibility is controlled by the administrator-managed registry. Only active, unexpired and conflict-free IDs grant student access.</p>
    <?php endif; ?>
</section>
