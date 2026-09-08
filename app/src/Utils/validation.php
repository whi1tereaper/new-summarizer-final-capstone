<?php
// Shared validation and security helpers used by the public PHP entry points.

function generateCsrfToken() {
    // Keep one token in the session so multiple forms on the same visit stay consistent.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Use this for redirect-style form posts that should bounce the user back on failure.
function validateCsrfToken(?string $token): void {
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash_error'] = "Invalid security token. Session may have expired.";
        header("Location: ../../public/index.php");
        exit;
    }
}

// Use this for controller or JSON flows that need a true/false result instead of an immediate redirect.
function verifyCsrfToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Rotate the token after successful writes so stale forms cannot be replayed indefinitely.
function rotateCsrfToken(): void {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Keep username rules centralized so registration and account checks reject the same bad input.
function validateUsername(string $username): ?string {
    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 30) {
        return 'Username must be between 3 and 30 characters.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return 'Username may only contain letters, numbers, and underscores.';
    }
    return null;
}


function isAcceptedCheckboxValue(mixed $value): bool {
    // Accept the common HTML checkbox values so controllers can read forms and session flags consistently.
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    if (!is_string($value)) {
        return false;
    }

    return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
}

// Return either a normalized email payload or a user-facing validation message.
function validateEmail(string $email): array|string {
    $email = strtolower(trim($email));
    if (strlen($email) === 0) {
        return 'Email address is required.';
    }
    if (strlen($email) > 100) {
        return 'Email address is too long.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Invalid email format.';
    }
    return ['email' => $email];
}
