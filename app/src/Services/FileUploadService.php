<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Support/RuntimePaths.php';
require_once __DIR__ . '/../Support/config.php';
require_once __DIR__ . '/../Support/ProcessingError.php';

use App\Src\Support\RuntimePaths;
use finfo;
use App\Src\Support\ProcessingError;

final class FileUploadService
{
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx'];
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    public const MAX_UPLOAD_BYTES = 30 * 1024 * 1024;

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int)$value;
        return $number * match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
    }

    public static function effectiveUploadLimit(): int
    {
        return min(array_filter([self::MAX_UPLOAD_BYTES, self::iniBytes((string)ini_get('upload_max_filesize')),
            self::iniBytes((string)ini_get('post_max_size'))], static fn($n) => $n > 0));
    }

    /** Validate genuine uploads before moving them or starting a worker. */
    public function assertUploadedFile(array $file, array $extensions = self::ALLOWED_EXTENSIONS,
        array $mimes = self::ALLOWED_MIMES, int $maxSize = self::MAX_UPLOAD_BYTES): void
    {
        $error = $file['error'] ?? -1;
        if ($error !== UPLOAD_ERR_OK) {
            throw new ProcessingError(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'FILE_TOO_LARGE',
                UPLOAD_ERR_PARTIAL => 'UPLOAD_INCOMPLETE', UPLOAD_ERR_NO_FILE => 'FILE_REQUIRED',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'UPLOAD_FAILED',
                default => 'INPUT_INVALID',
            });
        }
        $path = $file['tmp_name'] ?? null;
        if (!is_string($path) || !is_uploaded_file($path)) throw new ProcessingError('INPUT_INVALID');
        $size = filesize($path);
        if ($size === false) throw new ProcessingError('UPLOAD_FAILED');
        if ($size > min($maxSize, self::effectiveUploadLimit())) throw new ProcessingError('FILE_TOO_LARGE');
        $name = is_string($file['name'] ?? null) ? $file['name'] : '';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions, true)) throw new ProcessingError('FILE_UNSUPPORTED');
        if ($size === 0) throw new ProcessingError('FILE_CORRUPTED');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $head = file_get_contents($path, false, null, 0, 8);
        if ($extension === 'pdf') {
            if (!str_starts_with((string)$head, '%PDF-') || $mime !== 'application/pdf') {
                throw new ProcessingError('FILE_UNSUPPORTED');
            }
        } elseif ($extension === 'docx') {
            // Password-protected Office documents use an OLE container, not OOXML ZIP.
            if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
                throw new ProcessingError('FILE_UNSUPPORTED');
            }
            // XAMPP's older magic database reports genuine OOXML as octet-stream.
            // Admit that ambiguous MIME only with a ZIP signature AND Word package entries.
            if (!str_starts_with((string)$head, "PK\x03\x04")
                || !in_array($mime, ['application/zip', 'application/octet-stream', self::ALLOWED_MIMES[1]], true)) {
                throw new ProcessingError('FILE_UNSUPPORTED');
            }
            $archive = new \ZipArchive();
            if ($archive->open($path) !== true) throw new ProcessingError('FILE_CORRUPTED');
            try {
                // A ZIP extension alone is not proof that this is a Word document.
                if ($archive->locateName('[Content_Types].xml') === false || $archive->locateName('word/document.xml') === false) {
                    throw new ProcessingError('FILE_CORRUPTED');
                }
            } finally {
                $archive->close();
            }
            $mime = self::ALLOWED_MIMES[1];
        }
        if (!in_array($mime, $mimes, true)) throw new ProcessingError('FILE_UNSUPPORTED');
    }

    public function validateUploadedFile(
        array $file,
        array $allowedExtensions = self::ALLOWED_EXTENSIONS,
        array $allowedMimes = self::ALLOWED_MIMES,
        int $maxSizeBytes = self::MAX_UPLOAD_BYTES
    ): ?string {
        try {
            $this->assertUploadedFile($file, $allowedExtensions, $allowedMimes, $maxSizeBytes);
            return null;
        } catch (ProcessingError $error) {
            return $error->getMessage();
        }
    }
    public function generateSafeFilename(string $extension): string
    {
        return time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    public function saveUploadedFile(array $uploadedFile): string
    {
        $this->assertUploadedFile(
            $uploadedFile,
            self::ALLOWED_EXTENSIONS,
            self::ALLOWED_MIMES,
            self::MAX_UPLOAD_BYTES
        );

        $uploadDirectory = RuntimePaths::uploadsDirectory();
        if (!RuntimePaths::ensureDirectory($uploadDirectory, 0775)) {
            throw new ProcessingError('UPLOAD_FAILED');
        }

        $extension = strtolower(pathinfo($uploadedFile['name'] ?? '', PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ProcessingError('FILE_UNSUPPORTED');
        }

        $safeFilename = $this->generateSafeFilename($extension);
        $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $safeFilename;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
            throw new ProcessingError('UPLOAD_FAILED');
        }

        $realPath = realpath($destination);
        if ($realPath === false) {
            @unlink($destination);
            throw new ProcessingError('UPLOAD_FAILED');
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
