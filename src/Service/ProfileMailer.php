<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Teacher;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProfileMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TransportInterface $transport,
        private readonly BodyRendererInterface $bodyRenderer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $fromAddress,
        #[Autowire('%app.name%')]
        private readonly string $appName,
    ) {}

    public function sendPasswordReset(Teacher $teacher, string $token): void
    {
        $resetUrl = $this->urlGenerator->generate(
            'app_password_reset',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $fullName = $teacher->getName()->getFirstName() . ' ' . $teacher->getName()->getLastName();

        $this->sendSync((new TemplatedEmail())
            ->to(new Address((string) $teacher->getEmail(), $fullName))
            ->subject($this->translator->trans('emails.password_reset.subject', [], 'emails'))
            ->htmlTemplate('email/password_reset.html.twig')
            ->context([
                'teacher'   => $teacher,
                'reset_url' => $resetUrl,
            ]));
    }

    public function sendEmailVerification(Teacher $teacher, string $pendingEmail, string $token): void
    {
        $verifyUrl = $this->urlGenerator->generate(
            'app_profile_verify_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $fullName = $teacher->getName()->getFirstName() . ' ' . $teacher->getName()->getLastName();

        $this->send((new TemplatedEmail())
            ->to(new Address($pendingEmail, $fullName))
            ->subject($this->translator->trans('emails.email_verification.subject', [], 'emails'))
            ->htmlTemplate('email/email_verification.html.twig')
            ->context([
                'teacher'    => $teacher,
                'verify_url' => $verifyUrl,
            ]));
    }

    private function send(TemplatedEmail $email): void
    {
        $email->from(new Address($this->fromAddress, $this->appName));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('No se pudo enviar el email "{subject}": {error}', [
                'subject' => $email->getSubject(),
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envía el email de forma síncrona (sin pasar por el bus de mensajes),
     * renderizando la plantilla antes de entregarlo al transporte. Se usa para
     * el reset de contraseña: el token caduca en 1 h y el fallo debe poder
     * reportarse en la misma petición.
     */
    private function sendSync(TemplatedEmail $email): void
    {
        $email->from(new Address($this->fromAddress, $this->appName));

        try {
            $this->bodyRenderer->render($email);
            $this->transport->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('No se pudo enviar el email "{subject}": {error}', [
                'subject' => $email->getSubject(),
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
