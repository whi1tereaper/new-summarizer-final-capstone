<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Support/RuntimePaths.php';
require_once __DIR__ . '/../Support/config.php';
require_once __DIR__ . '/../Utils/validation.php';

use App\Src\Support\RuntimePaths;

final class UploadService
{
    // These limits are shared with validation so uploads are screened before they ever reach the worker.
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx'];
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    public const MAX_UPLOAD_BYTES = 30 * 1024 * 1024;

    public function saveUploadedFile(array $uploadedFile): string
    {
        // Validate the browser upload first so disk writes only happen for trusted files.
        $error = validateUploadedFile(
            $uploadedFile,
            self::ALLOWED_EXTENSIONS,
            self::ALLOWED_MIMES,
            self::MAX_UPLOAD_BYTES
        );

        if ($error !== null) {
            throw new \RuntimeException($error);
        }

        // Centralize upload storage under the app runtime path instead of scattering temp files.
        $uploadDirectory = RuntimePaths::uploadsDirectory();
        if (!RuntimePaths::ensureDirectory($uploadDirectory, 0775)) {
            throw new \RuntimeException('Upload storage is not writable.');
        }

        $extension = strtolower(pathinfo($uploadedFile['name'] ?? '', PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new \RuntimeException('Uploaded file has an invalid extension.');
        }

        // Replace the browser-provided filename so users cannot influence saved paths.
        $safeFilename = generateSafeFilename($extension);
        $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $safeFilename;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
            throw new \RuntimeException('Failed to save uploaded file. Please try again.');
        }

        $realPath = realpath($destination);
        if ($realPath === false) {
            throw new \RuntimeException('Failed to resolve uploaded file path.');
        }

        return $realPath;
    }

    public function removeUploadedFile(string $filePath): void
    {
        // Cleanup is best-effort because failed deletes should not block the user flow.
        if ($filePath === '' || !is_file($filePath)) {
            return;
        }

        @unlink($filePath);
    }
}
