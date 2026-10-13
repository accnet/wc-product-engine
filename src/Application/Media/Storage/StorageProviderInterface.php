<?php

declare(strict_types=1);

namespace WPE\Application\Media\Storage;

/**
 * Where the bytes of a managed image live (docs/10 managed upload).
 *
 * Implementations never fall back to another provider: a failure is reported as an exception and the caller
 * shows it and offers a retry.
 */
interface StorageProviderInterface
{
    /** Short provider name stored with every asset, for example s3, spaces, minio or local. */
    public function name(): string;

    /** Bucket stored with every asset; empty for local storage. */
    public function bucket(): string;

    /** True when the provider is configured and can accept an upload right now. */
    public function isAvailable(): bool;

    /** Operator-safe explanation when the provider is not available, otherwise null. */
    public function unavailableReason(): ?string;

    /** Object key for a relative path, with the provider's own prefix applied. */
    public function objectKey(string $relativePath): string;

    /**
     * Store a local file under the key. Throws StorageException on failure; never returns on a partial upload.
     */
    public function put(string $localPath, string $objectKey, string $mime): void;

    /** True only when the object exists and its size equals the expected size. */
    public function verify(string $objectKey, int $expectedSize): bool;

    /** Public delivery URL for the key as the provider and its CDN setting define it today. */
    public function urlFor(string $objectKey): string;

    /** Remove the object. Returns false when the provider could not confirm the removal. */
    public function delete(string $objectKey): bool;
}
