<?php

namespace Miles\SharedModuleKit;

/**
 * What happened to a delivered event. Suggested HTTP answers for the
 * module's events endpoint: accepted 201, duplicate 200, conflict 409.
 */
final readonly class InboxResult
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const CONFLICT = 'conflict';

    public function __construct(
        public string $status,
        public string $eventId,
        public string $originalEventId,
    ) {}

    public function httpStatus(): int
    {
        return match ($this->status) {
            self::ACCEPTED => 201,
            self::DUPLICATE => 200,
            default => 409,
        };
    }

    /**
     * @return array{status: string, event_id: string, original_event_id: string}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'event_id' => $this->eventId, 'original_event_id' => $this->originalEventId];
    }
}
