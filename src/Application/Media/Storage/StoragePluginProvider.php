<?php

declare(strict_types=1);

namespace WPE\Application\Media\Storage;

/**
 * Provider backed by the adapters of the object storage plugin (S3, Spaces, MinIO).
 *
 * The plugin owns credentials and configuration. This class only calls its adapter, in the required order:
 * upload, then verify that the object exists with the expected size. Settings and adapter are injected so the
 * behavior is testable without network access; fromPlugin builds the real pair when the plugin is loaded.
 */
final class StoragePluginProvider implements StorageProviderInterface
{
    public const SETTINGS_CLASS = 'ImagesSpace\\Application\\Settings';
    public const FACTORY_CLASS  = 'ImagesSpace\\Storage\\AdapterFactory';

    /**
     * @param object $settings object with storageConfig(): array and buildObjectKey(string): string
     * @param object $adapter  object with upload, exists, getMetadata, getUrl, delete, isConfigured, getLastError
     */
    public function __construct(private readonly object $settings, private readonly object $adapter)
    {
    }

    /** Whether the plugin's classes are loaded in this request. */
    public static function pluginLoaded(): bool
    {
        return class_exists(self::SETTINGS_CLASS) && class_exists(self::FACTORY_CLASS);
    }

    public static function fromPlugin(): ?self
    {
        if (!self::pluginLoaded()) {
            return null;
        }
        $settingsClass = self::SETTINGS_CLASS;
        $factoryClass  = self::FACTORY_CLASS;
        $settings      = new $settingsClass();
        $adapter       = (new $factoryClass($settings))->make();

        return new self($settings, $adapter);
    }

    public function name(): string
    {
        return (string) ($this->config()['provider'] ?? 's3');
    }

    public function bucket(): string
    {
        return (string) ($this->config()['bucket'] ?? '');
    }

    public function isAvailable(): bool
    {
        return (bool) $this->adapter->isConfigured();
    }

    public function unavailableReason(): ?string
    {
        if ($this->isAvailable()) {
            return null;
        }
        $error = StorageSanitizer::message((string) $this->adapter->getLastError());
        return $error !== '' ? $error : 'The object storage is not configured.';
    }

    public function objectKey(string $relativePath): string
    {
        return (string) $this->settings->buildObjectKey($relativePath);
    }

    public function put(string $localPath, string $objectKey, string $mime): void
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new StorageUnavailableException($reason);
        }
        if (!is_readable($localPath)) {
            throw new StorageException('The source file could not be read.', 'source_unreadable');
        }
        try {
            $stored = (bool) $this->adapter->upload($localPath, $objectKey, $mime, []);
        } catch (\Throwable $e) {
            throw new StorageException(StorageSanitizer::message($e->getMessage()) ?: 'The upload failed.');
        }
        if (!$stored) {
            $error = StorageSanitizer::message((string) $this->adapter->getLastError());
            throw new StorageException($error !== '' ? $error : 'The storage did not accept the file.');
        }
    }

    public function verify(string $objectKey, int $expectedSize): bool
    {
        try {
            if (!$this->adapter->exists($objectKey)) {
                return false;
            }
            $meta = $this->adapter->getMetadata($objectKey);
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($meta) || !isset($meta['ContentLength'])) {
            return false;
        }
        return (int) $meta['ContentLength'] === $expectedSize;
    }

    public function urlFor(string $objectKey): string
    {
        return (string) $this->adapter->getUrl($objectKey);
    }

    public function delete(string $objectKey): bool
    {
        try {
            return (bool) $this->adapter->delete($objectKey);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function config(): array
    {
        $config = $this->settings->storageConfig();
        return is_array($config) ? $config : [];
    }
}
