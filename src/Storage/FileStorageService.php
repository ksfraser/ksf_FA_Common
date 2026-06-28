<?php

declare(strict_types=1);

namespace KsfCommon\Storage;

use KsfCommon\Storage\Contract\FileStorageInterface;
use Ksfraser\Exceptions\Utility\FileNotFoundException;

class FileStorageService implements FileStorageInterface
{
    private const MAX_FILE_SIZE = 52428800;
    private const ALLOWED_MIME_PATTERNS = [
        '#^image/#',
        '#^application/pdf$#',
        '#^application/msword$#',
        '#^application/vnd\.openxmlformats-officedocument\.#',
        '#^application/vnd\.ms-#',
        '#^text/plain$#',
        '#^text/csv$#',
        '#^application/zip$#',
        '#^application/x-zip-compressed$#',
    ];

    private string $subDir;

    public function __construct(string $subDir = 'general')
    {
        $this->subDir = $subDir;
    }

    public function store(array $file, string $subDir = 'general'): array
    {
        $this->validateFile($file);

        $tmpName  = $file['tmp_name'];
        $filename = basename($file['name']);
        $fileSize = (int) ($file['size'] ?? 0);
        $mimeType = (string) ($file['type'] ?? 'application/octet-stream');

        $dir = $this->basePath($subDir);
        if (!is_dir($dir)) {
            $this->ensureDirectoryExists($dir);
        }

        $uniqueName = $this->generateUniqueName($filename);
        $destPath   = $dir . '/' . $uniqueName;

        if (!move_uploaded_file($tmpName, $destPath)) {
            throw new \RuntimeException('Failed to move uploaded file to ' . $destPath);
        }

        return [
            'unique_name' => $uniqueName,
            'filename'    => $filename,
            'file_size'   => $fileSize,
            'mime_type'   => $mimeType,
        ];
    }

    public function path(string $uniqueName, string $subDir = 'general'): string
    {
        $path = $this->basePath($subDir) . '/' . ltrim($uniqueName, '/');
        if (!file_exists($path)) {
            throw FileNotFoundException::withContext($path, "subDir={$subDir}");
        }
        return $path;
    }

    public function serve(string $uniqueName, string $filename, string $mimeType, string $subDir = 'general'): void
    {
        $filePath = $this->path($uniqueName, $subDir);
        $fileSize = filesize($filePath);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($filePath);
        exit;
    }

    public function delete(string $uniqueName, string $subDir = 'general'): bool
    {
        try {
            $filePath = $this->path($uniqueName, $subDir);
            return unlink($filePath);
        } catch (FileNotFoundException $e) {
            return false;
        }
    }

    public function basePath(string $subDir = 'general'): string
    {
        $base = $this->resolveCompanyPath() . '/attachments/' . ltrim($subDir, '/');
        return rtrim($base, '/');
    }

    protected function resolveCompanyPath(): string
    {
        if (function_exists('company_path')) {
            return company_path();
        }
        if (defined('TB_PREF') && defined('SYSTEM_USER')) {
            $comp = defined('user_company') ? user_company() : '0';
            $path = dirname(__DIR__, 5) . '/company/' . $comp;
            return $path;
        }
        return sys_get_temp_dir() . '/fa_attachments';
    }

    private function validateFile(array $file): void
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('No valid uploaded file provided');
        }
        if (!empty($file['error'])) {
            throw new \RuntimeException('File upload error: ' . $file['error']);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('Uploaded file is empty');
        }
        if ($size > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('File exceeds maximum allowed size of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB');
        }
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create storage directory: ' . $dir);
        }
        $indexFile = $dir . '/index.php';
        if (!file_exists($indexFile)) {
            file_put_contents($indexFile, "<?php\nheader('Location: ../index.php');\n");
        }
    }

    private function generateUniqueName(string $filename): string
    {
        if (function_exists('random_id')) {
            $base = random_id(128);
        } else {
            $base = bin2hex(random_bytes(32));
        }
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return $ext !== '' ? $base . '.' . $ext : $base;
    }
}
