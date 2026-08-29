<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\ProfileMailer;
use App\Tests\Integration\RepositoryTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProfileMailerTest extends RepositoryTestCase
{
    private function teacher(): Teacher
    {
        return (new Teacher(new PersonName('Ana', 'García')))->setUsername('agarcia')->setEmail('ana@example.com');
    }

    private function makeMailer(MailerInterface $mailer, TransportInterface $transport, LoggerInterface $logger): ProfileMailer
    {
        return new ProfileMailer(
            $mailer,
            $transport,
            self::getContainer()->get(BodyRendererInterface::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(TranslatorInterface::class),
            $logger,
            'no-reply@example.com',
            'ÁTICA Calidad',
        );
    }

    public function testSendPasswordResetLogsAndSwallowsATransportFailure(): void
    {
        $teacher = $this->teacher();
        $this->persist($teacher);

        $transport = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('SMTP caído');
            }

            public function __toString(): string
            {
                return 'failing://transport';
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $mailer = $this->makeMailer($this->createStub(MailerInterface::class), $transport, $logger);

        // Must not throw: the caller (password reset flow) needs to keep responding normally
        // even when the transport is down.
        $mailer->sendPasswordReset($teacher, 'un-token');
    }

    public function testSendEmailVerificationLogsAndSwallowsATransportFailure(): void
    {
        $teacher = $this->teacher();
        $this->persist($teacher);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('SMTP caído'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $profileMailer = $this->makeMailer($mailer, $this->createStub(TransportInterface::class), $logger);

        $profileMailer->sendEmailVerification($teacher, 'nueva@example.com', 'un-token');
    }

    public function testSendEmailVerificationSendsToThePendingAddressNotTheCurrentOne(): void
    {
        $teacher = $this->teacher();
        $this->persist($teacher);

        $sent   = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(function (RawMessage $message) use (&$sent): void {
            $sent = $message;
        });

        $logger        = $this->createStub(LoggerInterface::class);
        $profileMailer = $this->makeMailer($mailer, $this->createStub(TransportInterface::class), $logger);

        $profileMailer->sendEmailVerification($teacher, 'nueva@example.com', 'un-token');

        self::assertInstanceOf(Email::class, $sent);
        $to = $sent->getTo();
        self::assertCount(1, $to);
        self::assertSame('nueva@example.com', $to[0]->getAddress());
        self::assertSame('no-reply@example.com', $sent->getFrom()[0]->getAddress());
    }
}
