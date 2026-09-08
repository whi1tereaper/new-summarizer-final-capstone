<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Support/RuntimePaths.php';
require_once __DIR__ . '/../Support/config.php';

use App\Src\Support\RuntimePaths;
use finfo;

final class FileUploadService
{
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx'];
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    public const MAX_UPLOAD_BYTES = 30 * 1024 * 1024;

    public function validateUploadedFile(
        array $file,
        array $allowedExtensions = self::ALLOWED_EXTENSIONS,
        array $allowedMimes = self::ALLOWED_MIMES,
        int $maxSizeBytes = self::MAX_UPLOAD_BYTES
    ): ?string {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory missing.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
            ];
            $code = $file['error'] ?? -1;
            return $errorMessages[$code] ?? 'Unknown upload error.';
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return 'Invalid upload detected.';
        }

        if ($file['size'] > $maxSizeBytes) {
            $maxMb = round($maxSizeBytes / 1048576, 1);
            return "File exceeds maximum size of {$maxMb} MB.";
        }

        $originalName = $file['name'] ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $allowed = implode(', ', $allowedExtensions);
            return "Invalid file type. Allowed: {$allowed}.";
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        
        // finfo often detects modern office documents as generic zip files.
        if ($extension === 'docx' && $detectedMime === 'application/zip') {
            $detectedMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        if (!in_array($detectedMime, $allowedMimes, true)) {
            error_log("[Upload] MIME mismatch: claimed={$file['type']}, detected={$detectedMime}, file={$originalName}");
            return 'File content does not match expected format.';
        }

        return null;
    }

    public function generateSafeFilename(string $extension): string
    {
        return time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    public function saveUploadedFile(array $uploadedFile): string
    {
        $error = $this->validateUploadedFile(
            $uploadedFile,
            self::ALLOWED_EXTENSIONS,
            self::ALLOWED_MIMES,
            self::MAX_UPLOAD_BYTES
        );

        if ($error !== null) {
            throw new \RuntimeException($error);
        }

        $uploadDirectory = RuntimePaths::uploadsDirectory();
        if (!RuntimePaths::ensureDirectory($uploadDirectory, 0775)) {
            throw new \RuntimeException('Upload storage is not writable.');
        }

        $extension = strtolower(pathinfo($uploadedFile['name'] ?? '', PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new \RuntimeException('Uploaded file has an invalid extension.');
        }

        $safeFilename = $this->generateSafeFilename($extension);
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
        if ($filePath === '' || !is_file($filePath)) {
            return;
        }

        @unlink($filePath);
    }
}
