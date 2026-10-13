<?php

/**
 * In-memory storage provider for tests. Writes nothing to disk or network and can be told to fail at each step,
 * so the upload, retry and recovery rules can be exercised without real storage. This is a test double, not a
 * certification of any real provider.
 */

use WPE\Application\Media\Storage\StorageException;
use WPE\Application\Media\Storage\StorageProviderInterface;
use WPE\Application\Media\Storage\StorageUnavailableException;

final class FakeStorageProvider implements StorageProviderInterface
{
    /** @var array<string,string> object key => bytes */
    public array $objects = [];
    public int $putCalls = 0;
    public bool $available = true;
    public bool $failPut = false;
    public bool $failVerify = false;
    public bool $failDelete = false;
    public string $providerName = 'fake';
    public string $bucketName = 'fake-bucket';
    public string $prefix = 'fake-prefix';

    public function name(): string
    {
        return $this->providerName;
    }

    public function bucket(): string
    {
        return $this->bucketName;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function unavailableReason(): ?string
    {
        return $this->available ? null : 'Fake storage is offline.';
    }

    public function objectKey(string $relativePath): string
    {
        return $this->prefix . '/' . ltrim($relativePath, '/');
    }

    public function put(string $localPath, string $objectKey, string $mime): void
    {
        if (!$this->available) {
            throw new StorageUnavailableException('Fake storage is offline.');
        }
        $this->putCalls++;
        if ($this->failPut) {
            throw new StorageException('Fake storage rejected the file.');
        }
        $this->objects[$objectKey] = (string) file_get_contents($localPath);
    }

    public function verify(string $objectKey, int $expectedSize): bool
    {
        if ($this->failVerify) {
            return false;
        }
        return isset($this->objects[$objectKey]) && strlen($this->objects[$objectKey]) === $expectedSize;
    }

    public function urlFor(string $objectKey): string
    {
        return 'https://cdn.fake.test/' . $objectKey;
    }

    public function delete(string $objectKey): bool
    {
        if ($this->failDelete) {
            return false;
        }
        unset($this->objects[$objectKey]);
        return true;
    }
}
