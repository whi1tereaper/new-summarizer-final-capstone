<?php
declare(strict_types=1);

namespace App\Src\Services;

use RuntimeException;

final class NeonAuthService
{
    private string $baseUrl;
    private string $jwksUrl;
    private bool $enabled;

    public function __construct(?string $baseUrl = null, ?string $jwksUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('neon_auth.base_url', ''), '/');
        $this->jwksUrl = $jwksUrl ?? config('neon_auth.jwks_url', '');
        $this->enabled = $this->baseUrl !== '';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getJwksUrl(): string
    {
        return $this->jwksUrl;
    }

    /**
     * Sign up a new user via Neon Auth Managed Better Auth.
     *
     * @return array{success: bool, user: ?array, token: ?string, error: ?string}
     */
    public function signUp(string $name, string $email, string $password): array
    {
        if (!$this->enabled) {
            return ['success' => false, 'user' => null, 'token' => null, 'error' => 'Neon Auth is not configured.'];
        }

        $response = $this->request('/sign-up/email', 'POST', [
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
        ]);

        if ($response['status'] >= 200 && $response['status'] < 300 && isset($response['body']['user'])) {
            return [
                'success' => true,
                'user'    => $response['body']['user'],
                'token'   => $response['body']['token'] ?? null,
                'error'   => null,
            ];
        }

        $errorMessage = $response['body']['message'] ?? $response['body']['error'] ?? 'Sign up failed.';
        return [
            'success' => false,
            'user'    => null,
            'token'   => null,
            'error'   => (string)$errorMessage,
        ];
    }

    /**
     * Sign in an existing user via Neon Auth Managed Better Auth.
     *
     * @return array{success: bool, user: ?array, token: ?string, error: ?string}
     */
    public function signIn(string $email, string $password): array
    {
        if (!$this->enabled) {
            return ['success' => false, 'user' => null, 'token' => null, 'error' => 'Neon Auth is not configured.'];
        }

        $response = $this->request('/sign-in/email', 'POST', [
            'email'    => $email,
            'password' => $password,
        ]);

        if ($response['status'] >= 200 && $response['status'] < 300 && isset($response['body']['user'])) {
            return [
                'success' => true,
                'user'    => $response['body']['user'],
                'token'   => $response['body']['token'] ?? null,
                'error'   => null,
            ];
        }

        $errorMessage = $response['body']['message'] ?? $response['body']['error'] ?? 'Invalid credentials.';
        return [
            'success' => false,
            'user'    => null,
            'token'   => null,
            'error'   => (string)$errorMessage,
        ];
    }

    /**
     * Terminate the Neon Auth session.
     */
    public function signOut(?string $token = null): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $headers = [];
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $response = $this->request('/sign-out', 'POST', [], $headers);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * Fetch active session from Neon Auth.
     */
    public function getSession(?string $token = null): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $headers = [];
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $response = $this->request('/get-session', 'GET', [], $headers);
        if ($response['status'] >= 200 && $response['status'] < 300 && is_array($response['body'])) {
            return $response['body'];
        }

        return null;
    }

    /**
     * Fetch and verify public JWKS keys from Neon Auth.
     */
    public function fetchJwks(): array
    {
        $url = $this->jwksUrl !== '' ? $this->jwksUrl : ($this->baseUrl . '/.well-known/jwks.json');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($raw)) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Execute HTTP request against the Neon Auth API.
     */
    private function request(string $endpoint, string $method = 'GET', array $data = [], array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'Origin: ' . (config('app.url', 'http://localhost:8000')),
        ], $extraHeaders);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            return [
                'status' => $status > 0 ? $status : 500,
                'body'   => ['message' => 'Network error connecting to Neon Auth: ' . $curlError],
            ];
        }

        $decoded = json_decode((string)$raw, true);
        return [
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : ['raw' => (string)$raw],
        ];
    }
}
