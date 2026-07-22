<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Storage\Contract;

interface FileStorageInterface
{
    public function store(array $file, string $subDir = 'general'): array;

    public function path(string $uniqueName, string $subDir = 'general'): string;

    public function serve(string $uniqueName, string $filename, string $mimeType, string $subDir = 'general'): void;

    public function delete(string $uniqueName, string $subDir = 'general'): bool;

    public function basePath(string $subDir = 'general'): string;
}
