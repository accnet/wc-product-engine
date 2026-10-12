<?php

namespace WPE\Compatibility\WordPress;

use RuntimeException;
use InvalidArgumentException;

/**
 * Minimal PostIdAllocator for T06.
 * Allocates real WordPress post shell rows to reserve authoritative global IDs
 * without premature publication or hook triggers before canonical aggregate commit.
 * Fully expanded in T13 for routing, public shells, and factory projections.
 */
class PostIdAllocator
{
    private \wpdb $wpdb;

    public function __construct(?\wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    /**
     * Allocate a real post shell in wp_posts without publishing it.
     * Uses wp_insert_post or direct insert into wp_posts with status 'auto-draft'.
     *
     * @param string $postType 'product' or 'product_variation'
     * @return int The newly allocated WP post ID
     * @throws RuntimeException
     */
    public function allocateShell(string $postType = 'product'): int
    {
        if (function_exists('wp_insert_post')) {
            $postData = [
                'post_title'   => 'WPE Shell Pending',
                'post_status'  => 'auto-draft',
                'post_type'    => $postType,
                'post_content' => '',
                'post_excerpt' => '',
            ];
            $postId = wp_insert_post($postData, true);
            if (is_wp_error($postId) || !is_numeric($postId) || $postId <= 0) {
                $err = is_wp_error($postId) ? $postId->get_error_message() : 'Unknown error';
                throw new RuntimeException("Failed to allocate WordPress post shell: " . $err);
            }
            return (int)$postId;
        }

        $now = gmdate('Y-m-d H:i:s');
        $res = $this->wpdb->insert(
            $this->wpdb->posts,
            [
                'post_author'           => 0,
                'post_date'             => $now,
                'post_date_gmt'         => $now,
                'post_content'          => '',
                'post_title'            => 'WPE Shell Pending',
                'post_excerpt'          => '',
                'post_status'           => 'auto-draft',
                'comment_status'        => 'closed',
                'ping_status'           => 'closed',
                'post_name'             => '',
                'to_ping'               => '',
                'pinged'                => '',
                'post_modified'         => $now,
                'post_modified_gmt'     => $now,
                'post_content_filtered' => '',
                'post_parent'           => 0,
                'guid'                  => '',
                'menu_order'            => 0,
                'post_type'             => $postType,
                'post_mime_type'        => '',
                'comment_count'         => 0,
            ]
        );

        if ($res === false) {
            throw new RuntimeException("Failed to allocate post shell via DB: " . $this->wpdb->last_error);
        }

        return (int)$this->wpdb->insert_id;
    }

    /**
     * Check if a post exists in wp_posts table.
     */
    public function postExists(int $id): bool
    {
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->posts} WHERE ID = %d",
            $id
        ));
        return ((int)$count) > 0;
    }

    /**
     * Reuse an existing legacy post ID during migration (AC-T06-02).
     */
    public function reuseLegacyId(int $id, string $expectedType = 'product'): int
    {
        $post = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT ID, post_type FROM {$this->wpdb->posts} WHERE ID = %d",
            $id
        ));

        if (!$post) {
            throw new InvalidArgumentException("Cannot reuse legacy ID {$id}: post does not exist in wp_posts.");
        }

        return (int)$post->ID;
    }

    /**
     * Clean up orphaned shell reservations older than grace period without committed native aggregate.
     */
    public function cleanOrphanShells(int $graceSeconds = 3600): int
    {
        $threshold = gmdate('Y-m-d H:i:s', time() - $graceSeconds);
        $wpeProducts = $this->wpdb->prefix . 'wpe_products';
        $wpeVariants = $this->wpdb->prefix . 'wpe_variants';

        $sql = "SELECT p.ID FROM {$this->wpdb->posts} p
                LEFT JOIN {$wpeProducts} pr ON pr.id = p.ID
                LEFT JOIN {$wpeVariants} vr ON vr.id = p.ID
                WHERE p.post_status = 'auto-draft'
                  AND p.post_type IN ('product', 'product_variation')
                  AND p.post_date_gmt < %s
                  AND pr.id IS NULL
                  AND vr.id IS NULL";

        $orphanIds = $this->wpdb->get_col($this->wpdb->prepare($sql, $threshold));
        if (empty($orphanIds)) {
            return 0;
        }

        $deletedCount = 0;
        foreach ($orphanIds as $id) {
            if (function_exists('wp_delete_post')) {
                wp_delete_post((int)$id, true);
            } else {
                $this->wpdb->delete($this->wpdb->posts, ['ID' => (int)$id]);
            }
            $deletedCount++;
        }

        return $deletedCount;
    }
}
