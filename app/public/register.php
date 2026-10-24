<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';
require_once __DIR__ . '/../src/Services/StudentVerificationService.php';
require_once __DIR__ . '/../src/Support/StudentRegistrationForm.php';
$studentSetupError=\App\Src\Services\StudentVerificationService::configurationError();
$registrationFeedback=\App\Src\Support\StudentRegistrationForm::consume();
$studentValues=$registrationFeedback['values'];$registrationErrors=$registrationFeedback['errors'];
$registrationEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$registrationInvalid=static fn($field)=>!empty($registrationErrors[$field])?'true':'false';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$csrf_token = generateCsrfToken();
$termsAcceptedFromTermsPage = !empty($_SESSION['guest_terms_accepted'])||!empty($studentValues['terms_accept']);
$termsNotice = $termsAcceptedFromTermsPage
    ? 'Terms accepted. Complete this form to create your account.'
    : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — LIGHT</title>
    <meta name="description" content="Create your LIGHT account to summarize documents, save history, and view analytics.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
    <link rel="stylesheet" href="assets/css/student-access.css">
</head>
<body class="page-auth-layout">
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <main class="auth-page-shell">
        <section class="auth-panel" aria-label="Registration form">
            <div class="auth-wrapper">
                <div class="auth-card">

                    <!-- Mode Switcher -->
                    <nav class="auth-segmented-switch" aria-label="Account access options">
                        <a href="login.php" class="auth-switch-tab">Sign In</a>
                        <span class="auth-switch-tab auth-switch-tab--active" aria-current="page">Create Account</span>
                    </nav>

                    <!-- Card Header -->
                    <div class="auth-card-header">
                        <h1 class="auth-card-title">Create Account</h1>
                        <p class="auth-card-desc">Enter your eligible NEUST Peñaranda Off-Campus Student ID, email and chosen password. An active, unclaimed registry match activates your account immediately. No administrator review or email confirmation is required.</p>
                    </div>
                    
                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="message message-error" role="alert">
                            <svg class="message-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
                            <?php unset($_SESSION['error']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($termsNotice !== ''): ?>
                        <div class="message message-success" role="status">
                            <?= htmlspecialchars($termsNotice) ?>
                        </div>
                    <?php endif; ?>

                    <?php if($registrationErrors): ?><div class="message message-error registration-error-summary" id="registration-errors" role="alert" tabindex="-1"><p>Check the highlighted fields:</p><ul><?php foreach($registrationErrors as $field=>$message): ?><li><a href="#<?= $registrationEscape(in_array($field,['student_number','username','email','password','confirm_password','terms_accept'],true)?$field:'register-form') ?>"><?= $registrationEscape($message) ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
                    <?php if($studentSetupError): ?><div class="message message-error" role="status"><?= htmlspecialchars($studentSetupError) ?></div><?php endif; ?>
                    <form method="POST" action="auth.php?action=register" id="register-form" data-registration-unavailable="<?= $studentSetupError?'1':'0' ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <?php require __DIR__.'/partials/student-application-fields.php'; ?>
                        
                        <div class="form-group">
                            <label for="username">Preferred Username (optional)</label>
                            <div class="auth-input-group">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                </span>
                                <input type="text" id="username" name="username" class="auth-input" minlength="3" maxlength="30" placeholder="Leave blank to generate one" autocomplete="username" value="<?= $registrationEscape($studentValues['username']??'') ?>" aria-invalid="<?= $registrationInvalid('username') ?>" aria-describedby="username-error">
                            </div>
                            <p id="username-error" class="registration-field-error" aria-live="polite" <?= empty($registrationErrors['username'])?'hidden':'' ?>><?= $registrationEscape($registrationErrors['username']??'') ?></p>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="auth-input-group">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                </span>
                                <input type="email" id="email" name="email" class="auth-input" placeholder="Your email address" autocomplete="email" maxlength="100" required value="<?= $registrationEscape($studentValues['email']??'') ?>" aria-invalid="<?= $registrationInvalid('email') ?>" aria-describedby="email-guide email-error">
                            </div>
                            <p id="email-guide" class="password-guide">Used for sign-in and account recovery. No email confirmation or OTP is required to register.</p>
                            <p id="email-error" class="registration-field-error" aria-live="polite" <?= empty($registrationErrors['email'])?'hidden':'' ?>><?= $registrationEscape($registrationErrors['email']??'') ?></p>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="auth-input-group pw-input-wrap">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                </span>
                                <input type="password" id="password" name="password" class="auth-input" required placeholder="8+ chars, incl. numbers" autocomplete="new-password" aria-invalid="<?= $registrationInvalid('password') ?>" aria-describedby="password-guide password-error">
                                <button type="button" class="pw-toggle" id="toggle-password" aria-label="Show password" aria-pressed="false">
                                    <svg class="pw-icon pw-icon--show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="pw-icon pw-icon--hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                            <p class="password-guide" id="password-guide">Use at least 8 characters, including a number and an uppercase letter or special character.</p>
                            <p id="password-error" class="registration-field-error" aria-live="polite" <?= empty($registrationErrors['password'])?'hidden':'' ?>><?= $registrationEscape($registrationErrors['password']??'') ?></p>
                            <div class="pw-strength" id="pw-strength-container" aria-hidden="true">
                                <div class="pw-strength__bar">
                                    <div class="pw-strength__fill" id="pw-strength-fill"></div>
                                </div>
                                <span class="pw-strength__label" id="pw-strength-label"></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="auth-input-group pw-input-wrap">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                </span>
                                <input type="password" id="confirm_password" name="confirm_password" class="auth-input" required placeholder="Retype password" autocomplete="new-password" aria-invalid="<?= $registrationInvalid('confirm_password') ?>" aria-describedby="confirm_password-error">
                                <button type="button" class="pw-toggle" id="toggle-confirm" aria-label="Show password" aria-pressed="false">
                                    <svg class="pw-icon pw-icon--show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="pw-icon pw-icon--hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                            <p id="confirm_password-error" class="registration-field-error" aria-live="polite" <?= empty($registrationErrors['confirm_password'])?'hidden':'' ?>><?= $registrationEscape($registrationErrors['confirm_password']??'') ?></p>
                        </div>

                        <label class="consent-box consent-box-standalone">
                            <input type="checkbox" id="terms_accept" name="terms_accept" value="1" required aria-invalid="<?= $registrationInvalid('terms_accept') ?>" aria-describedby="terms_accept-error"<?= $termsAcceptedFromTermsPage ? ' checked' : '' ?>>
                            <span class="consent-copy">
                                I have read and agree to the
                                <a href="terms.php?return=register.php" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>.
                            </span>
                        </label>
                        <p id="terms_accept-error" class="registration-field-error" aria-live="polite" <?= empty($registrationErrors['terms_accept'])?'hidden':'' ?>><?= $registrationEscape($registrationErrors['terms_accept']??'') ?></p>
                        <p id="registration-progress" class="password-guide" role="status" aria-live="polite"></p>

                        <button type="submit" class="btn-auth btn-auth-enhanced" id="btn-submit-register" <?= $studentSetupError?'disabled':'' ?>>
                            <span class="btn-auth__text">Create Account</span>
                            <span class="btn-auth__icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </span>
                        </button>
                    </form>

                    <div class="auth-nav-links">
                        <span>Already have an account? <a href="login.php">Sign In</a></span>
                    </div>

                </div>
            </div>
        </section>
    </main>

    <script src="assets/js/student-registration.js?v=<?= filemtime(__DIR__.'/assets/js/student-registration.js') ?>" defer></script>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
