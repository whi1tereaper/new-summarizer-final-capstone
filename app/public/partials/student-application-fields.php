<?php
$studentValues=$studentValues??[];
$studentEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<div class="form-group">
 <label for="student_number">Student ID Number</label>
 <div class="student-number-input"><span class="student-number-prefix" aria-hidden="true">PEN</span><input type="text" class="auth-input" id="student_number" name="student_number" minlength="6" maxlength="37" required spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="2023-02071" pattern="[0-9]{4}-[0-9]+" aria-label="Student ID number after the fixed PEN prefix" aria-invalid="<?= !empty($registrationErrors['student_number'])?'true':'false' ?>" aria-describedby="student-number-guide student_number-error" value="<?= $studentEscape(preg_replace('/^PEN/i','',(string)($studentValues['student_number']??''))) ?>"></div>
 <p class="password-guide" id="student-number-guide">PEN is fixed. Enter the remaining year and number, such as 2023-02071. Keep leading zeros. You can also paste your complete ID.</p>
 <p id="student_number-error" class="registration-field-error" aria-live="polite" <?= empty($registrationErrors['student_number'])?'hidden':'' ?>><?= $studentEscape($registrationErrors['student_number']??'') ?></p>
</div>
<p class="password-guide">An active, unclaimed ID in the administrator-managed database approves your account automatically. No photos, documents, student OTP, email verification or additional approval is required. A database match proves list eligibility, not rightful ownership; keep your ID and password private.</p>
