<?php

declare(strict_types=1);

namespace WPE\Application\Media\Storage;

/**
 * Chooses the system default storage for new uploads.
 *
 * Rule: when the object storage plugin's settings say it is enabled, that provider is the default; otherwise the
 * local uploads directory is. If the default is enabled but cannot be used (plugin not loaded, not configured,
 * connection problem) the answer is an exception, never a different provider.
 *
 * Existing assets keep the provider, bucket and key stored with them; forStored only returns a provider when
 * it is the one that holds the object today.
 */
final class StorageResolver
{
    public const SETTINGS_OPTION = 'images_space_settings';

    /** @var callable():?StorageProviderInterface */
    private $pluginFactory;
    /** @var callable():bool */
    private $enabledReader;
    private LocalStorageProvider $local;

    /**
     * @param callable():?StorageProviderInterface|null $pluginFactory builds the plugin provider or null when not loaded
     * @param callable():bool|null                      $enabledReader whether the plugin settings enable object storage
     */
    public function __construct(?callable $pluginFactory = null, ?callable $enabledReader = null, ?LocalStorageProvider $local = null)
    {
        $this->pluginFactory = $pluginFactory ?? static fn (): ?StorageProviderInterface => StoragePluginProvider::fromPlugin();
        $this->enabledReader = $enabledReader ?? static function (): bool {
            $settings = get_option(self::SETTINGS_OPTION, []);
            return is_array($settings) && !empty($settings['enabled']);
        };
        $this->local = $local ?? new LocalStorageProvider();
    }

    /** Name of the default storage without touching any credentials: a provider name or local. */
    public function defaultIsObjectStorage(): bool
    {
        return (bool) ($this->enabledReader)();
    }

    public function default(): StorageProviderInterface
    {
        if (!$this->defaultIsObjectStorage()) {
            $reason = $this->local->unavailableReason();
            if ($reason !== null) {
                throw new StorageUnavailableException($reason);
            }
            return $this->local;
        }

        $provider = ($this->pluginFactory)();
        if ($provider === null) {
            throw new StorageUnavailableException(
                'Object storage is enabled as the default, but the storage plugin is not loaded. Uploads are not redirected to local storage.'
            );
        }
        $reason = $provider->unavailableReason();
        if ($reason !== null) {
            throw new StorageUnavailableException('Object storage is enabled as the default but cannot be used: ' . $reason);
        }
        return $provider;
    }

    /**
     * Provider that can act on an already stored object (for example to delete it). Returns the provider only
     * when it matches the stored provider and bucket; a changed default never touches objects of another one.
     */
    public function forStored(string $provider, string $bucket): StorageProviderInterface
    {
        if ($provider === 'local') {
            $reason = $this->local->unavailableReason();
            if ($reason !== null) {
                throw new StorageUnavailableException($reason);
            }
            return $this->local;
        }

        $current = ($this->defaultIsObjectStorage()) ? ($this->pluginFactory)() : null;
        if ($current === null || $current->name() !== $provider || $current->bucket() !== $bucket) {
            throw new StorageUnavailableException(
                'The storage that holds this file is not the one currently configured.',
                'provider_mismatch'
            );
        }
        $reason = $current->unavailableReason();
        if ($reason !== null) {
            throw new StorageUnavailableException($reason);
        }
        return $current;
    }
}
