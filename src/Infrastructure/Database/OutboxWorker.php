<?php

namespace WPE\Infrastructure\Database;

class OutboxWorker
{
    private \wpdb $wpdb;
    private string $tableOutbox;
    private string $tableJobs;
    private string $tableControl;
    private string $tableAudit;
    private string $tableObjectState;

    public function __construct(?\wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $prefix = $this->wpdb->prefix;
        $this->tableOutbox = "{$prefix}wpe_outbox";
        $this->tableJobs = "{$prefix}wpe_jobs";
        $this->tableControl = "{$prefix}wpe_control";
        $this->tableAudit = "{$prefix}wpe_audit";
        $this->tableObjectState = "{$prefix}wpe_object_state";
    }

    /**
     * Publish an event into the outbox table inside current transaction.
     */
    public function publishEvent(int $aggregateId, int $revision, int $epoch, string $eventType, array $payload): string
    {
        $eventId = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s.u');

        $res = $this->wpdb->insert(
            $this->tableOutbox,
            [
                'event_id' => $eventId,
                'aggregate_id' => $aggregateId,
                'revision' => $revision,
                'epoch' => $epoch,
                'event_type' => $eventType,
                'payload' => wp_json_encode($payload),
                'state' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
            ],
            ['%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($res === false) {
            throw new \RuntimeException("Failed to publish outbox event: " . $this->wpdb->last_error);
        }

        return $eventId;
    }

    /**
     * Acquire a batch of due (available_at reached) pending/failed or expired-lease outbox events atomically (R34).
     * Events whose retry backoff (available_at) is still in the future are never handed out.
     */
    public function acquireBatch(string $workerId, int $batchSize = 10, int $leaseSeconds = 30): array
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $leaseUntil = gmdate('Y-m-d H:i:s.u', time() + $leaseSeconds);

        // Select IDs of candidate events (pending or expired lease)
        $candidates = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT event_id FROM {$this->tableOutbox}
             WHERE (state IN ('pending', 'failed') AND available_at <= %s)
                OR (state = 'processing' AND lease_until < %s)
             ORDER BY available_at ASC, created_at ASC
             LIMIT %d",
            $now,
            $now,
            $batchSize
        ));

        if (empty($candidates)) {
            return [];
        }

        $acquired = [];
        foreach ($candidates as $id) {
            // Atomic CAS takeover
            $updated = $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableOutbox}
                 SET state = 'processing', lease_owner = %s, lease_until = %s, attempts = attempts + 1
                 WHERE event_id = %s
                   AND ((state IN ('pending', 'failed') AND available_at <= %s) OR lease_until < %s)",
                $workerId,
                $leaseUntil,
                $id,
                $now,
                $now
            ));

            if ($updated > 0) {
                $row = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT * FROM {$this->tableOutbox} WHERE event_id = %s",
                    $id
                ), ARRAY_A);
                if ($row) {
                    $row['payload'] = json_decode((string)$row['payload'], true);
                    $acquired[] = $row;
                }
            }
        }

        return $acquired;
    }

    /**
     * Mark an outbox event as completed.
     */
    public function completeEvent(string $eventId): bool
    {
        return (bool)$this->wpdb->update(
            $this->tableOutbox,
            ['state' => 'completed', 'lease_owner' => null, 'lease_until' => null],
            ['event_id' => $eventId],
            ['%s', null, null],
            ['%s']
        );
    }

    /**
     * Mark an outbox event as failed or dead_letter.
     */
    public function failEvent(string $eventId, string $error, int $maxAttempts = 5): bool
    {
        $event = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT attempts FROM {$this->tableOutbox} WHERE event_id = %s",
            $eventId
        ));

        $attempts = $event ? (int)$event->attempts : 1;
        $state = ($attempts >= $maxAttempts) ? 'dead_letter' : 'failed';
        $retryDelay = min(300, pow(2, $attempts) * 2);
        $availableAt = gmdate('Y-m-d H:i:s.u', time() + $retryDelay);

        return (bool)$this->wpdb->update(
            $this->tableOutbox,
            [
                'state' => $state,
                'last_error' => substr($error, 0, 65535),
                'available_at' => $availableAt,
                'lease_owner' => null,
                'lease_until' => null,
            ],
            ['event_id' => $eventId],
            ['%s', '%s', '%s', null, null],
            ['%s']
        );
    }

    /**
     * Monotonic revision check: apply event only if revision is greater than currently applied revision (R33).
     */
    public function applyWithRevisionFence(int $objectId, int $incomingRevision, callable $callback): bool
    {
        $currentState = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT native_applied, native_revision FROM {$this->tableObjectState} WHERE object_id = %d",
            $objectId
        ));

        $currentApplied = $currentState ? (int)$currentState->native_applied : 0;

        if ($incomingRevision <= $currentApplied) {
            // Out of order or duplicate event: ignore without reverting newer data
            return false;
        }

        // Execute application logic
        $result = $callback();

        // Update native_applied revision atomically
        $now = gmdate('Y-m-d H:i:s.u');
        if ($currentState) {
            $this->wpdb->update(
                $this->tableObjectState,
                [
                    'native_applied' => $incomingRevision,
                    'updated_at' => $now,
                ],
                ['object_id' => $objectId],
                ['%d', '%s'],
                ['%d']
            );
        } else {
            $this->wpdb->insert(
                $this->tableObjectState,
                [
                    'object_id' => $objectId,
                    'kind' => 'product',
                    'authority' => 'native',
                    'epoch' => 1,
                    'legacy_revision' => 1,
                    'native_revision' => $incomingRevision,
                    'legacy_applied' => 0,
                    'native_applied' => $incomingRevision,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s']
            );
        }

        return (bool)$result;
    }

    /**
     * Check or record idempotency key (R35).
     * Reusing the same key with different bodyHash returns 'idempotency_conflict'.
     */
    public function checkOrRecordIdempotency(string $key, string $bodyHash, ?array $resultPayload = null): array
    {
        $ctrlKey = 'idempotency:' . $key;
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT value, version FROM {$this->tableControl} WHERE `key` = %s",
            $ctrlKey
        ));

        if ($row) {
            $data = json_decode((string)$row->value, true);
            if (($data['body_hash'] ?? '') !== $bodyHash) {
                return [
                    'status' => 'conflict',
                    'error' => 'idempotency_conflict',
                    'message' => 'Idempotency key reused with different request payload.',
                ];
            }
            return [
                'status' => 'reused',
                'cached_result' => $data['result'] ?? [],
            ];
        }

        // Record new key
        $now = gmdate('Y-m-d H:i:s.u');
        $payload = [
            'body_hash' => $bodyHash,
            'result' => $resultPayload ?? ['success' => true],
            'created_at' => $now,
        ];

        $this->wpdb->insert(
            $this->tableControl,
            [
                'key' => $ctrlKey,
                'value' => wp_json_encode($payload),
                'version' => 1,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%d', '%s']
        );

        return [
            'status' => 'recorded',
        ];
    }

    /**
     * Epoch management and verification (R36).
     */
    public function getCurrentEpoch(): int
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = 'current_epoch'"
        ));

        if (!$row) {
            $now = gmdate('Y-m-d H:i:s.u');
            $this->wpdb->insert(
                $this->tableControl,
                ['key' => 'current_epoch', 'value' => json_encode(['epoch' => 1]), 'version' => 1, 'updated_at' => $now],
                ['%s', '%s', '%d', '%s']
            );
            return 1;
        }

        $val = json_decode((string)$row->value, true);
        return (int)($val['epoch'] ?? 1);
    }

    public function incrementEpoch(): int
    {
        $current = $this->getCurrentEpoch();
        $next = $current + 1;
        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->update(
            $this->tableControl,
            ['value' => json_encode(['epoch' => $next]), 'version' => $next, 'updated_at' => $now],
            ['key' => 'current_epoch'],
            ['%s', '%d', '%s'],
            ['%s']
        );

        return $next;
    }

    public function verifyEpoch(int $expectedEpoch): bool
    {
        return $expectedEpoch === $this->getCurrentEpoch();
    }

    /**
     * Log audit record with actor_id and request_id (AC-T09-02).
     * Redacts secrets before recording.
     */
    public function logAudit(string $operation, ?int $targetId, ?int $actorId, ?string $requestId, array $summary): int
    {
        // Redact any sensitive credentials or secrets
        $sanitized = $this->redactSensitive($summary);
        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->insert(
            $this->tableAudit,
            [
                'actor_id' => $actorId,
                'request_id' => $requestId ?? wp_generate_uuid4(),
                'operation' => $operation,
                'target_id' => $targetId,
                'summary' => wp_json_encode($sanitized),
                'created_at' => $now,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );

        return (int)$this->wpdb->insert_id;
    }

    private function redactSensitive(array $data): array
    {
        $sensitiveKeys = ['password', 'secret', 'token', 'auth', 'api_key', 'authorization'];
        $clean = [];

        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $sensitiveKeys, true)) {
                $clean[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $clean[$k] = $this->redactSensitive($v);
            } else {
                $clean[$k] = $v;
            }
        }

        return $clean;
    }
}
