<?php

namespace Miles\SharedModuleKit;

use PDO;
use Throwable;

/**
 * Exactly-once processing of business events.
 *
 * The inbox row and the module's own effects (posting a journal, updating an
 * employee) are written in one transaction, keyed by
 * (source_app, organization_id, source_transaction_id):
 *
 *  - first delivery: stored and processed            -> accepted
 *  - same transaction id, same content (a retry)     -> duplicate, not processed again
 *  - same transaction id, different content          -> conflict, not processed
 *
 * If processing throws, nothing is kept, so the product's retry can succeed.
 * The table is created by schema/postgres.sql.
 */
final class Inbox
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'shared_module_inbox',
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param  callable(Envelope): void  $process  the module's handler, run once per event
     *
     * @throws EventRejected when the token does not vouch for this envelope
     */
    public function accept(Envelope $envelope, EventToken $token, callable $process): InboxResult
    {
        if ($token->organizationId !== $envelope->organizationId) {
            throw new EventRejected('organization_mismatch', 'The event is for a different organization than the token.');
        }

        if ($token->sourceApp !== $envelope->sourceApp) {
            throw new EventRejected('source_mismatch', 'The event names a different source application than the token.');
        }

        $startedTransaction = ! $this->pdo->inTransaction();
        $committed = false;
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO {$this->table} (event_id, source_app, organization_id, source_transaction_id, event_type, schema_version, content_hash, payload, occurred_at, received_at)
                 VALUES (:event_id, :source_app, :organization_id, :source_transaction_id, :event_type, :schema_version, :content_hash, :payload, :occurred_at, CURRENT_TIMESTAMP)
                 ON CONFLICT (source_app, organization_id, source_transaction_id) DO NOTHING"
            );
            $insert->execute([
                'event_id' => $envelope->eventId,
                'source_app' => $envelope->sourceApp,
                'organization_id' => $envelope->organizationId,
                'source_transaction_id' => $envelope->sourceTransactionId,
                'event_type' => $envelope->eventType,
                'schema_version' => $envelope->schemaVersion,
                'content_hash' => $envelope->contentHash(),
                'payload' => json_encode($envelope->payload, JSON_THROW_ON_ERROR),
                'occurred_at' => $envelope->occurredAt->format(DATE_ATOM),
            ]);

            if ($insert->rowCount() === 0) {
                $existing = $this->existing($envelope);

                if ($startedTransaction) {
                    $this->pdo->commit();
                    $committed = true;
                }

                return $existing['content_hash'] === $envelope->contentHash()
                    ? new InboxResult(InboxResult::DUPLICATE, $envelope->eventId, (string) $existing['event_id'])
                    : new InboxResult(InboxResult::CONFLICT, $envelope->eventId, (string) $existing['event_id']);
            }

            $process($envelope);

            $this->pdo->prepare("UPDATE {$this->table} SET processed_at = CURRENT_TIMESTAMP WHERE event_id = :event_id")
                ->execute(['event_id' => $envelope->eventId]);

            if ($startedTransaction) {
                $this->pdo->commit();
                $committed = true;
            }
        } catch (Throwable $e) {
            // Only roll back what this call opened and has not committed yet.
            if ($startedTransaction && ! $committed) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return new InboxResult(InboxResult::ACCEPTED, $envelope->eventId, $envelope->eventId);
    }

    /**
     * @return array{event_id: string, content_hash: string}
     */
    private function existing(Envelope $envelope): array
    {
        $select = $this->pdo->prepare(
            "SELECT event_id, content_hash FROM {$this->table}
             WHERE source_app = :source_app AND organization_id = :organization_id AND source_transaction_id = :source_transaction_id"
        );
        $select->execute([
            'source_app' => $envelope->sourceApp,
            'organization_id' => $envelope->organizationId,
            'source_transaction_id' => $envelope->sourceTransactionId,
        ]);

        /** @var array{event_id: string, content_hash: string} */
        return $select->fetch(PDO::FETCH_ASSOC);
    }
}
