<?php
/**
 * T09 Integration Test — Outbox, jobs, epoch, and idempotency persistence verification.
 *
 * Verifies R32 (outbox_commit_crash), R33 (duplicate_out_of_order_events),
 * R34 (worker_lease_takeover), R35 (key_body_conflict), and R36 (stale_epoch).
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

class T09Test
{
    private \WPE\Infrastructure\Database\OutboxWorker $worker;
    private \wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->worker = new \WPE\Infrastructure\Database\OutboxWorker($wpdb);
    }

    public function run(): int
    {
        echo "=== Running T09 Integration Tests ===\n";

        $table = $this->wpdb->prefix . 'wpe_outbox';
        $parked = $this->wpdb->get_results(
            "SELECT event_id, state FROM {$table} WHERE state IN ('pending','failed','processing')",
            ARRAY_A
        );
        $byState = [];
        foreach ($parked as $row) {
            $byState[$row['state']][] = $row['event_id'];
        }
        foreach ($byState as $state => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_map(fn($id) => "'" . esc_sql($id) . "'", $chunk));
                $this->wpdb->query("UPDATE {$table} SET state='test_parked' WHERE event_id IN ({$in})");
            }
        }
        echo "[isolation] parked " . count($parked) . " pre-existing outbox events for the test.\n";

        try {
            $r32 = $this->testOutboxCommitCrash();
            $r33 = $this->testDuplicateOutOfOrderEvents();
            $r34 = $this->testWorkerLeaseTakeover();
            $backoff = $this->testRetryBackoffHonorsAvailableAt();
            $r35 = $this->testKeyBodyConflict();
            $r36 = $this->testStaleEpoch();
            $ac02 = $this->testAuditActorRequestId();
        } finally {
            foreach ($byState as $state => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    $in = implode(',', array_map(fn($id) => "'" . esc_sql($id) . "'", $chunk));
                    $this->wpdb->query($this->wpdb->prepare(
                        "UPDATE {$table} SET state=%s WHERE state='test_parked' AND event_id IN ({$in})",
                        $state
                    ));
                }
            }
            echo "[isolation] restored " . count($parked) . " pre-existing outbox events.\n";
        }

        echo "\n=== Test Summary ===\n";
        echo "R32 (outbox_commit_crash): " . ($r32 ? "PASSED" : "FAILED") . "\n";
        echo "R33 (duplicate_out_of_order_events): " . ($r33 ? "PASSED" : "FAILED") . "\n";
        echo "R34 (worker_lease_takeover): " . ($r34 ? "PASSED" : "FAILED") . "\n";
        echo "R35 (key_body_conflict): " . ($r35 ? "PASSED" : "FAILED") . "\n";
        echo "R36 (stale_epoch): " . ($r36 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T09-02 (audit_actor_request_id): " . ($ac02 ? "PASSED" : "FAILED") . "\n";
        $ac01 = $r32 && $r33 && $r34 && $r36 && $backoff;
        echo "AC-T09-01 (crash_duplicate_resume_epoch_backoff): " . ($ac01 ? "PASSED" : "FAILED") . "\n";

        if ($r32 && $r33 && $r34 && $r35 && $r36 && $ac01 && $ac02) {
            echo "All T09 tests passed successfully.\n";
            return 0;
        }

        echo "T09 tests failed.\n";
        return 1;
    }

    /**
     * R32: Outbox event committed persists across crashes.
     */
    private function testOutboxCommitCrash(): bool
    {
        echo "[R32] Verifying outbox event durability across crash simulation...\n";

        $aggregateId = mt_rand(710000, 719999);
        $revision = 1;
        $epoch = 1;
        $payload = ['action' => 'product_created', 'sku' => 'TEST-R32-' . $aggregateId];

        $eventId = $this->worker->publishEvent($aggregateId, $revision, $epoch, 'product.created', $payload);
        if (empty($eventId)) {
            echo "  [FAIL] Failed to publish outbox event.\n";
            return false;
        }

        // Simulate crash: worker process dies and new instance checks DB
        $freshWorker = new \WPE\Infrastructure\Database\OutboxWorker($this->wpdb);
        $batch = $freshWorker->acquireBatch('worker-crash-test', 10, 30);

        $found = false;
        foreach ($batch as $ev) {
            if ($ev['event_id'] === $eventId) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            echo "  [FAIL] Committed outbox event was not retrieved by fresh worker after simulated crash.\n";
            return false;
        }

        $freshWorker->completeEvent($eventId);
        echo "  [OK] Outbox event persisted and successfully processed after crash.\n";
        return true;
    }

    /**
     * R33: Duplicate or out of order events do not overwrite newer data.
     */
    private function testDuplicateOutOfOrderEvents(): bool
    {
        echo "[R33] Verifying monotonic revision fence against duplicate/out-of-order events...\n";

        $objectId = mt_rand(720000, 729999);

        // Apply revision 2 first
        $appliedRev2 = $this->worker->applyWithRevisionFence($objectId, 2, function () {
            return true;
        });

        if (!$appliedRev2) {
            echo "  [FAIL] Fresh revision 2 should be applied.\n";
            return false;
        }
        echo "  [OK] Applied revision 2.\n";

        // Now an older revision 1 arrives (out of order or delayed)
        $executedOlder = false;
        $appliedRev1 = $this->worker->applyWithRevisionFence($objectId, 1, function () use (&$executedOlder) {
            $executedOlder = true;
            return true;
        });

        if ($appliedRev1 !== false || $executedOlder === true) {
            echo "  [FAIL] Older revision 1 was incorrectly applied over newer revision 2!\n";
            return false;
        }
        echo "  [OK] Older revision 1 was safely rejected by monotonic fence.\n";

        // Duplicate revision 2 arrives
        $executedDuplicate = false;
        $appliedDup2 = $this->worker->applyWithRevisionFence($objectId, 2, function () use (&$executedDuplicate) {
            $executedDuplicate = true;
            return true;
        });

        if ($appliedDup2 !== false || $executedDuplicate === true) {
            echo "  [FAIL] Duplicate revision 2 was incorrectly executed!\n";
            return false;
        }
        echo "  [OK] Duplicate revision 2 was safely rejected.\n";

        return true;
    }

    /**
     * R34: Worker lease takeover when previous worker expires.
     */
    private function testWorkerLeaseTakeover(): bool
    {
        echo "[R34] Verifying atomic worker lease takeover on expiration...\n";

        $aggregateId = mt_rand(730000, 739999);
        $eventId = $this->worker->publishEvent($aggregateId, 1, 1, 'stock.reserved', ['qty' => 2]);

        // Worker A acquires lease for only 1 second
        $batchA = $this->worker->acquireBatch('worker-A', 1, 1);
        if (empty($batchA) || $batchA[0]['event_id'] !== $eventId) {
            echo "  [FAIL] Worker A could not acquire initial lease.\n";
            return false;
        }
        echo "  [OK] Worker A acquired initial lease.\n";

        // Worker B tries immediately while lease is active -> should get nothing
        $batchB1 = $this->worker->acquireBatch('worker-B', 1, 10);
        $foundB1 = false;
        foreach ($batchB1 as $ev) {
            if ($ev['event_id'] === $eventId) {
                $foundB1 = true;
            }
        }
        if ($foundB1) {
            echo "  [FAIL] Worker B acquired lease before Worker A's lease expired.\n";
            return false;
        }
        echo "  [OK] Active lease protected against concurrent worker takeover.\n";

        // Wait for lease to expire
        sleep(2);

        // Worker B tries again -> should take over expired lease
        $batchB2 = $this->worker->acquireBatch('worker-B', 1, 30);
        $foundB2 = false;
        foreach ($batchB2 as $ev) {
            if ($ev['event_id'] === $eventId) {
                $foundB2 = true;
                break;
            }
        }

        if (!$foundB2) {
            echo "  [FAIL] Worker B could not take over expired lease from crashed Worker A.\n";
            return false;
        }

        $this->worker->completeEvent($eventId);
        echo "  [OK] Worker B successfully took over expired lease via atomic CAS.\n";
        return true;
    }

    /**
     * Retry backoff: a failed event whose available_at is in the future must not be handed out (F15).
     */
    private function testRetryBackoffHonorsAvailableAt(): bool
    {
        echo "[AC-T09-01] Verifying retry backoff (available_at) is honored by acquireBatch...\n";

        $table = $this->wpdb->prefix . 'wpe_outbox';
        $eventId = $this->worker->publishEvent(mt_rand(740000, 749999), 1, 1, 'stock.reserved', ['qty' => 1]);
        if (empty($eventId)) {
            echo "  [FAIL] Failed to publish outbox event.\n";
            return false;
        }
        $batch = $this->worker->acquireBatch('worker-backoff', 10, 30);
        if (!in_array($eventId, array_column($batch, 'event_id'), true)) {
            echo "  [FAIL] Due event was not handed out.\n";
            return false;
        }
        $this->worker->failEvent($eventId, 'simulated handler failure');
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT state, available_at FROM {$table} WHERE event_id = %s", $eventId), ARRAY_A);
        if (($row['state'] ?? '') !== 'failed' || strtotime($row['available_at'] . ' UTC') <= time()) {
            echo "  [FAIL] failEvent did not schedule a future retry.\n";
            return false;
        }

        $early = $this->worker->acquireBatch('worker-backoff', 10, 30);
        if (in_array($eventId, array_column($early, 'event_id'), true)) {
            echo "  [FAIL] Failed event was handed out before its backoff elapsed.\n";
            return false;
        }
        echo "  [OK] Failed event is withheld until available_at.\n";

        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$table} SET available_at = %s WHERE event_id = %s",
            gmdate('Y-m-d H:i:s.u', time() - 5),
            $eventId
        ));
        $due = $this->worker->acquireBatch('worker-backoff', 10, 30);
        if (!in_array($eventId, array_column($due, 'event_id'), true)) {
            echo "  [FAIL] Failed event was not retried after its backoff elapsed.\n";
            return false;
        }
        $this->worker->completeEvent($eventId);
        echo "  [OK] Failed event is retried once available_at has passed.\n";
        return true;
    }

    /**
     * R35: Idempotency key reused with different body is rejected.
     */
    private function testKeyBodyConflict(): bool
    {
        echo "[R35] Verifying idempotency key conflict detection...\n";

        $key = 'test-idemp-key-' . uniqid();
        $hash1 = hash('sha256', 'payload-A');
        $hash2 = hash('sha256', 'payload-B');

        // First call with hash1
        $res1 = $this->worker->checkOrRecordIdempotency($key, $hash1, ['result_id' => 101]);
        if ($res1['status'] !== 'recorded') {
            echo "  [FAIL] First idempotency call failed.\n";
            return false;
        }
        echo "  [OK] First idempotency key recorded.\n";

        // Reusing same key with same hash -> returns cached result
        $resReused = $this->worker->checkOrRecordIdempotency($key, $hash1);
        if ($resReused['status'] !== 'reused') {
            echo "  [FAIL] Reusing same key with same body should return cached result.\n";
            return false;
        }
        echo "  [OK] Idempotent retry returns cached result.\n";

        // Reusing same key with DIFFERENT hash -> conflict!
        $resConflict = $this->worker->checkOrRecordIdempotency($key, $hash2);
        if (($resConflict['error'] ?? '') !== 'idempotency_conflict') {
            echo "  [FAIL] Reusing same key with different body must return idempotency_conflict.\n";
            return false;
        }
        echo "  [OK] Idempotency conflict detected and rejected.\n";

        return true;
    }

    /**
     * R36: Stale epoch command rejection.
     */
    private function testStaleEpoch(): bool
    {
        echo "[R36] Verifying stale epoch command rejection...\n";

        $currentEpoch = $this->worker->getCurrentEpoch();
        if ($currentEpoch < 1) {
            echo "  [FAIL] Epoch should be at least 1.\n";
            return false;
        }

        // Test with current epoch
        if (!$this->worker->verifyEpoch($currentEpoch)) {
            echo "  [FAIL] Verification of current epoch failed.\n";
            return false;
        }

        // Increment epoch
        $newEpoch = $this->worker->incrementEpoch();
        echo "  [OK] Epoch incremented from {$currentEpoch} to {$newEpoch}.\n";

        // Now test command with old/stale epoch
        if ($this->worker->verifyEpoch($currentEpoch)) {
            echo "  [FAIL] Old epoch {$currentEpoch} should have been rejected as stale!\n";
            return false;
        }
        echo "  [OK] Stale epoch correctly rejected.\n";

        return true;
    }

    /**
     * AC-T09-02: Audit logging includes actor_id and request_id and redacts secrets.
     */
    private function testAuditActorRequestId(): bool
    {
        echo "[AC-T09-02] Verifying audit logging with actor/request ID and secret redaction...\n";

        $actorId = 1;
        $requestId = wp_generate_uuid4();
        $summary = [
            'action' => 'update_product',
            'api_key' => 'super_secret_token_12345',
            'price' => '150.00'
        ];

        $auditId = $this->worker->logAudit('product.update', 700001, $actorId, $requestId, $summary);
        if ($auditId <= 0) {
            echo "  [FAIL] Audit log insert failed.\n";
            return false;
        }

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}wpe_audit WHERE id = %d",
            $auditId
        ));

        if (!$row || (int)$row->actor_id !== $actorId || $row->request_id !== $requestId) {
            echo "  [FAIL] Audit row missing actor_id or request_id.\n";
            return false;
        }

        if (str_contains($row->summary, 'super_secret_token_12345') || !str_contains($row->summary, '[REDACTED]')) {
            echo "  [FAIL] Audit row leaked secret!\n";
            return false;
        }

        echo "  [OK] Audit row verified with actor_id, request_id, and secret redaction.\n";
        return true;
    }
}

$test = new T09Test();
$exit_code = $test->run();
exit($exit_code);
