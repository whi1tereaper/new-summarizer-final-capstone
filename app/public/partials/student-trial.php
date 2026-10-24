<?php
require_once __DIR__.'/../../src/Services/CampusAccessService.php';
$trialStatus=null;$trialError='';
try{$trialService=new \App\Src\Services\CampusAccessService();$trialStatus=$trialService->status(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,$trialService->visitorToken());}catch(\Throwable $e){$trialError='Summarization access is unavailable until the access-control setup is complete.';}
$trialExhausted=$trialStatus!==null&&!$trialStatus['continued']&&$trialStatus['remaining']===0;
$enrollmentLink=(string)config('student_access.enrollment_url','');
$enrollmentValid=filter_var($enrollmentLink,FILTER_VALIDATE_URL)&&parse_url($enrollmentLink,PHP_URL_SCHEME)==='https';
?>
<link rel="stylesheet" href="assets/css/student-access.css">
<section class="student-access student-trial" id="student-trial" data-blocked="<?= $trialExhausted||$trialError?'1':'0' ?>" aria-label="Summary access">
<p id="student-trial-counter" role="status" aria-live="polite"><?= htmlspecialchars($trialError?:($trialStatus['continued']?'Continued account access · fair-use controls apply':$trialStatus['remaining'].' free '.($trialStatus['remaining']===1?'summary':'summaries').' remaining'),ENT_QUOTES,'UTF-8') ?></p>
<div id="student-trial-exhausted" <?= $trialExhausted?'':'hidden' ?>><h2>You've Used All 3 Free Summaries!</h2><p>Thank you for trying Light Summarizer. Continued student access requires an active Student ID in the administrator-managed database. Your existing results have not been removed.</p><h3>Already a NEUST Peñaranda Student?</h3><p>Register with your approved Student ID for immediate access. No photos, student OTP or additional approval is required. Creating an account does not reset the trial.</p>
<a class="student-button" href="<?= empty($_SESSION['user_id'])?'register.php':'profile.php' ?>"><?= empty($_SESSION['user_id'])?'Register as a Student':'View My Account' ?></a>
<?php if($enrollmentValid): ?><a class="student-button" href="<?= htmlspecialchars($enrollmentLink,ENT_QUOTES,'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Learn About NEUST Enrollment</a><?php endif; ?>
<a href="login.php">Login to Existing Account</a><p>Not yet enrolled? Explore the university's official admissions channels. A LIGHT account is not university enrollment.</p></div></section>
<script src="assets/js/student-access.js" defer></script>
