<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The messages the worker gave up on after its retries (the `failed` transport): e-mails that never left, a reminder, a
 * purge. Listed so an administrator notices them, and retried or discarded one by one.
 *
 * Retrying sends the message to the `async` transport (where the worker picks it up) and removes it from `failed`: it is
 * never dispatched through the bus from the web request, because messages without routing (the scheduled ones) would run
 * inside the HTTP request.
 */
class FailedMessages
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly TransportInterface $failed,
        #[Autowire(service: 'messenger.transport.async')]
        private readonly TransportInterface $async,
    ) {}

    /** How many there are, or null if the transport cannot say (an unreachable table). */
    public function count(): ?int
    {
        if (!$this->failed instanceof MessageCountAwareInterface) {
            return null;
        }

        try {
            return $this->failed->getMessageCount();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{id: string, message: string, error: string, at: \DateTimeImmutable|null}>|null null if they cannot be read
     */
    public function list(int $limit = 20): ?array
    {
        if (!$this->failed instanceof ListableReceiverInterface) {
            return null;
        }

        try {
            $rows = [];
            foreach ($this->failed->all($limit) as $envelope) {
                $id = $envelope->last(TransportMessageIdStamp::class)?->getId();
                if (!\is_int($id) && !\is_string($id)) {
                    continue;
                }

                $redelivered = $envelope->last(RedeliveryStamp::class)?->getRedeliveredAt();

                $rows[] = [
                    'id'      => (string) $id,
                    'message' => (new \ReflectionClass($envelope->getMessage()))->getShortName(),
                    'error'   => mb_strimwidth($envelope->last(ErrorDetailsStamp::class)?->getExceptionMessage() ?? '', 0, 300, '…'),
                    'at'      => $redelivered === null ? null : \DateTimeImmutable::createFromInterface($redelivered),
                ];
            }

            return $rows;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Puts the message back in the queue the worker consumes and takes it out of the failed ones. Returns its class, or null if it no longer exists. */
    public function retry(string $id): ?string
    {
        $envelope = $this->find($id);
        if ($envelope === null) {
            return null;
        }

        $this->async->send(new Envelope($envelope->getMessage()));
        $this->failed->reject($envelope);

        return (new \ReflectionClass($envelope->getMessage()))->getShortName();
    }

    /** Discards the message for good. Returns its class, or null if it no longer exists. */
    public function discard(string $id): ?string
    {
        $envelope = $this->find($id);
        if ($envelope === null) {
            return null;
        }

        $this->failed->reject($envelope);

        return (new \ReflectionClass($envelope->getMessage()))->getShortName();
    }

    private function find(string $id): ?Envelope
    {
        if (!$this->failed instanceof ListableReceiverInterface) {
            return null;
        }

        try {
            return $this->failed->find($id);
        } catch (\Throwable) {
            return null;
        }
    }
}
