<?php

namespace WPE\Compatibility\WordPress;

use RuntimeException;
use InvalidArgumentException;

/**
 * Allocates authoritative metadata IDs from the global WordPress wp_postmeta table.
 * Adheres to 03 §5: avoids isolated native AUTO_INCREMENT for meta_id,
 * ensuring seamless coexistence, migration parity, and mirror synchronization.
 */
class MetaIdAllocator
{
    private \wpdb $wpdb;

    public function __construct(?\wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    /**
     * Reserve an authoritative meta ID from WordPress wp_postmeta.
     *
     * @param int $objectId WordPress post / product ID
     * @param string $metaKey
     * @return int The allocated meta_id from wp_postmeta
     * @throws RuntimeException
     */
    public function allocateMetaId(int $objectId, string $metaKey = '_wpe_anchor'): int
    {
        $res = $this->wpdb->insert(
            $this->wpdb->postmeta,
            [
                'post_id'    => $objectId,
                'meta_key'   => $metaKey,
                'meta_value' => 'pending',
            ],
            ['%d', '%s', '%s']
        );

        if ($res === false) {
            throw new RuntimeException("Failed to allocate WordPress postmeta ID: " . $this->wpdb->last_error);
        }

        return (int)$this->wpdb->insert_id;
    }

    /**
     * Verify and reuse an existing legacy meta_id during migration.
     */
    public function reuseLegacyMetaId(int $metaId): int
    {
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_id FROM {$this->wpdb->postmeta} WHERE meta_id = %d",
            $metaId
        ));

        if (!$exists) {
            throw new InvalidArgumentException("Cannot reuse legacy meta_id {$metaId}: row not found in wp_postmeta.");
        }

        return (int)$exists;
    }

    /**
     * Clean up orphaned anchor reservations not committed to wpe_meta.
     */
    public function cleanOrphanAnchors(int $graceSeconds = 3600): int
    {
        $wpeMeta = $this->wpdb->prefix . 'wpe_meta';
        $sql = "SELECT pm.meta_id FROM {$this->wpdb->postmeta} pm
                LEFT JOIN {$wpeMeta} wm ON wm.meta_id = pm.meta_id
                WHERE pm.meta_key = '_wpe_anchor'
                  AND wm.meta_id IS NULL";

        $orphans = $this->wpdb->get_col($sql);
        if (empty($orphans)) {
            return 0;
        }

        $deleted = 0;
        foreach ($orphans as $metaId) {
            $this->wpdb->delete($this->wpdb->postmeta, ['meta_id' => (int)$metaId]);
            $deleted++;
        }

        return $deleted;
    }
}
