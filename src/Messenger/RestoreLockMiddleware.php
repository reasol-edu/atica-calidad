<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Service\Backup\RestoreState;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Keeps the worker's hands off the database while a backup is being restored. The web answers 503 meanwhile
 * (RestoreGuardSubscriber), but the worker is another process: restoring a backup from an earlier version drops every table and
 * migrates them again, and a scheduled task (the search index sweep, OCR, a reminder) that wrote in the middle would fail or,
 * worse, write into a half-built schema.
 *
 * Only what the worker receives is held back — a message sent from the web or the console goes through. A scheduler tick is
 * skipped (the same task comes round again, so nothing is lost); any other message is put back to try again later, without
 * using up its retries.
 */
final class RestoreLockMiddleware implements MiddlewareInterface
{
    /** Milliseconds before a held-back message is tried again. */
    private const RETRY_DELAY = 30_000;

    public function __construct(private readonly RestoreState $state) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class);
        if ($received === null || !$this->state->isRunning()) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (str_starts_with($received->getTransportName(), 'scheduler_')) {
            return $envelope;
        }

        throw new RecoverableMessageHandlingException('A backup is being restored: the message waits.', 0, null, self::RETRY_DELAY);
    }
}
