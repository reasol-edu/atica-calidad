<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends a one-line test e-mail so an administrator can check the mail transport is set up. Synchronously and straight
 * to the transport (not through the message bus, which would only queue it): a failure comes back at once, with the
 * transport's own message. Only ever to the address given, which the caller takes from the logged-in administrator.
 */
class TestEmailSender
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $fromAddress,
        #[Autowire('%app.name%')]
        private readonly string $appName,
    ) {}

    /** @return string|null why it failed, or null if the transport accepted it */
    public function send(string $to): ?string
    {
        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->appName))
            ->to($to)
            ->subject($this->translator->trans('emails.test.subject', ['%app%' => $this->appName], 'emails'))
            ->text($this->translator->trans('emails.test.body', ['%app%' => $this->appName], 'emails'));

        try {
            $this->transport->send($email);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
