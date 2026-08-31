<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use App\Entity\Teacher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

use function Symfony\Component\Clock\now;

/**
 * Generic outbound-email notifier for future event-driven notifications (activity reminders,
 * etc.): gates sending on the teacher's own "email notifications enabled" setting, then logs the
 * attempt to EmailNotificationLog when the centre has logging enabled — mirroring the gated-send-
 * then-log pattern, but content-agnostic (callers supply the copy) rather than one method per
 * domain event.
 */
final class NotificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AppSettingsInterface $settings,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $fromAddress,
        #[Autowire('%app.name%')]
        private readonly string $appName,
    ) {}

    /**
     * @param string $eventKey identifies the triggering event for the log/admin view (e.g. 'activity_reminder'), max 50 chars
     */
    public function send(
        Teacher $recipient,
        EducationalCentre $centre,
        string $eventKey,
        string $subject,
        string $heading,
        string $bodyHtml,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): void {
        if (!$this->settings->getForTeacherInCentre('notifications.email_notifications_enabled', $recipient, $centre)) {
            return;
        }

        $fullName = $recipient->getName()->getFirstName() . ' ' . $recipient->getName()->getLastName();

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->appName))
            ->to(new Address((string) $recipient->getEmail(), $fullName))
            ->subject($subject)
            ->htmlTemplate('email/notification.html.twig')
            ->context([
                'heading'     => $heading,
                'bodyHtml'    => $bodyHtml,
                'actionUrl'   => $actionUrl,
                'actionLabel' => $actionLabel,
            ]);

        $success      = true;
        $errorMessage = null;

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $success      = false;
            $errorMessage = $e->getMessage();
            $this->logger->error('No se pudo enviar el aviso "{subject}": {error}', [
                'subject' => $subject,
                'error'   => $errorMessage,
            ]);
        }

        $this->logNotification($centre, $recipient, $fullName, $eventKey, $subject, $success, $errorMessage);
    }

    private function logNotification(
        EducationalCentre $centre,
        Teacher $recipient,
        string $recipientName,
        string $eventKey,
        string $subject,
        bool $success,
        ?string $errorMessage,
    ): void {
        if (!$this->settings->getForCentre('notifications.email_log_enabled', $centre)) {
            return;
        }

        $this->em->persist(new EmailNotificationLog(
            $centre,
            $recipient,
            $recipientName,
            $eventKey,
            $subject,
            $success,
            $errorMessage,
            now(),
        ));
        $this->em->flush();
    }
}
