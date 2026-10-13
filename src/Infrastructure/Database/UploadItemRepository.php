<?php

declare(strict_types=1);

namespace WPE\Infrastructure\Database;

use RuntimeException;

/**
 * Upload intents for managed media (docs/03 `upload_items`).
 *
 * One row per (user, client upload key). The row is written before any bytes are sent to storage and advanced
 * as the upload progresses, so a retry with the same key resumes from the stored state instead of uploading or
 * registering twice. The row never makes an image available: assets are created only after the object is
 * confirmed and the row reaches `registered`.
 */
class UploadItemRepository
{
    public const STATE_RECEIVING  = 'receiving';
    public const STATE_STORED     = 'stored';
    public const STATE_REGISTERED = 'registered';
    public const STATE_FAILED     = 'failed';
    public const STATE_CLEANED    = 'cleaned';

    /** Default retention before an unreferenced stored object becomes a cleanup candidate. */
    public const DEFAULT_TTL_SECONDS = 172800;

    private \wpdb $wpdb;
    private string $table;

    public function __construct(?\wpdb $wpdb = null)
    {
        $this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
        $this->table = $this->wpdb->prefix . 'wpe_upload_items';
    }

    public function tableName(): string
    {
        return $this->table;
    }

    /**
     * Make sure the additive media tables exist. The migrator only issues create-if-not-exists statements, so
     * this is safe to call on a live site. Returns false when the tables are still missing afterwards.
     */
    public static function ensureSchema(?\wpdb $wpdb = null): bool
    {
        $wpdb   = $wpdb ?? $GLOBALS['wpdb'];
        $needed = [$wpdb->prefix . 'wpe_asset_storage', $wpdb->prefix . 'wpe_upload_items'];

        $missing = static function () use ($wpdb, $needed): array {
            $out = [];
            foreach ($needed as $table) {
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                    $out[] = $table;
                }
            }
            return $out;
        };

        if ($missing() === []) {
            return true;
        }

        (new SchemaMigrator($wpdb))->migrate();
        return $missing() === [];
    }

    /**
     * Find the intent for a user and upload key, or create it in the receiving state.
     * Concurrent creators resolve through the unique key: the loser reads the winner's row.
     */
    public function begin(int $userId, string $uploadKey, string $sessionId, ?int $productId, string $fileName, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $existing = $this->find($userId, $uploadKey);
        if ($existing !== null) {
            return $existing;
        }

        $now     = gmdate('Y-m-d H:i:s.u');
        $expires = gmdate('Y-m-d H:i:s.u', time() + max(60, $ttlSeconds));

        $productSql = $productId !== null && $productId > 0 ? (string) (int) $productId : 'NULL';

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->table}
                (upload_key, user_id, session_id, product_id, state, file_name, bucket, attempts, created_at, updated_at, expires_at)
             VALUES (%s, %d, %s, {$productSql}, %s, %s, '', 1, %s, %s, %s)",
            $uploadKey,
            $userId,
            $sessionId,
            self::STATE_RECEIVING,
            mb_substr($fileName, 0, 255),
            $now,
            $now,
            $expires
        ));

        $row = $this->find($userId, $uploadKey);
        if ($row === null) {
            throw new RuntimeException('Failed to create upload item: ' . $this->wpdb->last_error);
        }
        return $row;
    }

    public function find(int $userId, string $uploadKey): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->columns()} FROM {$this->table} WHERE user_id = %d AND upload_key = %s LIMIT 1",
            $userId,
            $uploadKey
        ), ARRAY_A);
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->columns()} FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Record that the object exists at the provider with the verified size. Does not create an asset.
     *
     * @param array{mime:string,size_bytes:int,content_hash:string,storage_provider:string,bucket:string,object_key:string} $data
     */
    public function markStored(int $id, array $data): void
    {
        $this->update($id, [
            'state'            => self::STATE_STORED,
            'mime'             => (string) $data['mime'],
            'size_bytes'       => (int) $data['size_bytes'],
            'storage_provider' => (string) $data['storage_provider'],
            'bucket'           => (string) $data['bucket'],
            'object_key'       => (string) $data['object_key'],
            'error_reason'     => null,
            'error_message'    => null,
        ], [
            'content_hash'    => (string) $data['content_hash'],
            'object_key_hash' => hash('sha256', $data['storage_provider'] . "\0" . $data['bucket'] . "\0" . $data['object_key']),
        ]);
    }

    public function markRegistered(int $id, int $assetId): void
    {
        $this->update($id, [
            'state'         => self::STATE_REGISTERED,
            'asset_id'      => $assetId,
            'error_reason'  => null,
            'error_message' => null,
        ]);
    }

    public function markFailed(int $id, string $reason, string $message): void
    {
        $this->update($id, [
            'state'         => self::STATE_FAILED,
            'error_reason'  => mb_substr($reason, 0, 64),
            'error_message' => wp_strip_all_tags($message),
        ]);
    }

    public function markCleaned(int $id): void
    {
        $this->update($id, ['state' => self::STATE_CLEANED]);
    }

    /**
     * A failed intent that is retried goes back to receiving with a higher attempt counter.
     */
    public function reopen(int $id): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table}
                SET state = %s, attempts = attempts + 1, error_reason = NULL, error_message = NULL, updated_at = %s
              WHERE id = %d AND state = %s",
            self::STATE_RECEIVING,
            gmdate('Y-m-d H:i:s.u'),
            $id,
            self::STATE_FAILED
        ));
    }

    /**
     * Give the uploads of an anonymous session to a product that was just created. Only the session owner can
     * claim, and only items that do not belong to a product yet.
     */
    public function claimSession(string $sessionId, int $userId, int $productId): int
    {
        $res = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table}
                SET product_id = %d, updated_at = %s
              WHERE session_id = %s AND user_id = %d AND product_id IS NULL AND state IN (%s, %s)",
            $productId,
            gmdate('Y-m-d H:i:s.u'),
            $sessionId,
            $userId,
            self::STATE_STORED,
            self::STATE_REGISTERED
        ));
        return $res === false ? 0 : (int) $res;
    }

    /**
     * Registered uploads of a product that are waiting for a library link (the caller decides which ones
     * are still unlinked).
     *
     * @return array<int,array>
     */
    public function listRegisteredForProduct(int $productId, int $userId): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$this->columns()} FROM {$this->table}
              WHERE product_id = %d AND user_id = %d AND state = %s ORDER BY id ASC",
            $productId,
            $userId,
            self::STATE_REGISTERED
        ), ARRAY_A) ?: [];
    }

    /**
     * Stored objects that never became an asset and were last touched before the cutoff.
     *
     * @return array<int,array>
     */
    public function listStaleStored(string $olderThan, int $limit = 50): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$this->columns()} FROM {$this->table}
              WHERE state = %s AND asset_id IS NULL AND updated_at < %s ORDER BY id ASC LIMIT %d",
            self::STATE_STORED,
            $olderThan,
            max(1, $limit)
        ), ARRAY_A) ?: [];
    }

    private function update(int $id, array $values, array $hexColumns = []): void
    {
        $sets   = ['updated_at = %s'];
        $params = [gmdate('Y-m-d H:i:s.u')];

        foreach ($values as $column => $value) {
            if ($value === null) {
                $sets[] = "{$column} = NULL";
                continue;
            }
            $sets[]   = is_int($value) ? "{$column} = %d" : "{$column} = %s";
            $params[] = $value;
        }
        foreach ($hexColumns as $column => $hex) {
            $sets[]   = "{$column} = UNHEX(%s)";
            $params[] = $hex;
        }
        $params[] = $id;

        $res = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET " . implode(', ', $sets) . ' WHERE id = %d',
            ...$params
        ));
        if ($res === false) {
            throw new RuntimeException('Failed to update upload item: ' . $this->wpdb->last_error);
        }
    }

    private function columns(): string
    {
        return 'id, upload_key, user_id, session_id, product_id, state, file_name, mime, size_bytes, '
            . 'HEX(content_hash) AS content_hash, storage_provider, bucket, object_key, '
            . 'HEX(object_key_hash) AS object_key_hash, asset_id, error_reason, error_message, attempts, '
            . 'created_at, updated_at, expires_at';
    }
}
