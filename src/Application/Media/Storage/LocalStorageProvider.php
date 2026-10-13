<?php

declare(strict_types=1);

namespace WPE\Application\Media\Storage;

/**
 * Local uploads directory provider, used only when no object storage is enabled as the system default.
 * Objects live under a dedicated folder of the uploads directory; keys are validated so a key can never leave it.
 */
final class LocalStorageProvider implements StorageProviderInterface
{
    public const FOLDER = 'wpe-products';

    private string $baseDir;
    private string $baseUrl;

    public function __construct(?string $baseDir = null, ?string $baseUrl = null)
    {
        $uploads       = wp_upload_dir(null, false);
        $this->baseDir = rtrim($baseDir ?? (string) ($uploads['basedir'] ?? ''), '/');
        $this->baseUrl = rtrim($baseUrl ?? (string) ($uploads['baseurl'] ?? ''), '/');
    }

    public function name(): string
    {
        return 'local';
    }

    public function bucket(): string
    {
        return '';
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(): ?string
    {
        if ($this->baseDir === '' || $this->baseUrl === '') {
            return 'The uploads directory is not available.';
        }
        $folder = $this->baseDir . '/' . self::FOLDER;
        if (is_dir($folder) ? !is_writable($folder) : !is_writable($this->baseDir)) {
            return 'The uploads directory is not writable.';
        }
        return null;
    }

    public function objectKey(string $relativePath): string
    {
        return self::FOLDER . '/' . ltrim($relativePath, '/');
    }

    public function put(string $localPath, string $objectKey, string $mime): void
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new StorageUnavailableException($reason);
        }
        $target = $this->pathFor($objectKey);
        if (!is_readable($localPath)) {
            throw new StorageException('The source file could not be read.', 'source_unreadable');
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new StorageException('The upload folder could not be created.');
        }
        $partial = $target . '.part-' . bin2hex(random_bytes(4));
        if (!copy($localPath, $partial) || !rename($partial, $target)) {
            @unlink($partial);
            throw new StorageException('The file could not be written to the uploads directory.');
        }
    }

    public function verify(string $objectKey, int $expectedSize): bool
    {
        $path = $this->pathFor($objectKey);
        return is_file($path) && filesize($path) === $expectedSize;
    }

    public function urlFor(string $objectKey): string
    {
        $this->pathFor($objectKey);
        return $this->baseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));
    }

    public function delete(string $objectKey): bool
    {
        $path = $this->pathFor($objectKey);
        return !is_file($path) || @unlink($path);
    }

    private function pathFor(string $objectKey): string
    {
        if (
            !preg_match('#^' . preg_quote(self::FOLDER, '#') . '/[A-Za-z0-9._\-/]+$#', $objectKey)
            || str_contains($objectKey, '..')
            || str_contains($objectKey, '//')
        ) {
            throw new StorageException('The object key is not valid.', 'invalid_key');
        }
        return $this->baseDir . '/' . $objectKey;
    }
}
