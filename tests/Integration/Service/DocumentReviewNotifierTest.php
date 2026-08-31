<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\EmailNotificationLogRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Service\AppSettingsInterface;
use App\Service\DocumentReviewNotifier;
use App\Service\NotificationMailer;
use App\Tests\Integration\RepositoryTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DocumentReviewNotifierTest extends RepositoryTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username)->setEmail("{$username}@example.com");
    }

    private function booleanDefinition(string $key, string $default): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::Boolean)->setDefaultValue($default)->setTeacherScope(true);
    }

    private function notifier(MailerInterface $mailer): DocumentReviewNotifier
    {
        $notificationMailer = new NotificationMailer(
            $mailer,
            self::getContainer()->get(AppSettingsInterface::class),
            new NullLogger(),
            $this->em,
            'no-reply@example.com',
            'ÁTICA Calidad',
        );

        return new DocumentReviewNotifier(
            self::getContainer()->get(SpecificProfileAssignmentRepository::class),
            self::getContainer()->get(AppSettingsInterface::class),
            $notificationMailer,
            self::getContainer()->get(TranslatorInterface::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(Environment::class),
        );
    }

    private function logs(): EmailNotificationLogRepository
    {
        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);

        return $logs;
    }

    /** @return array{centre: EducationalCentre, folder: Folder, revision: DocumentRevision, section: DocumentSection, document: Document, file: DocumentFile} */
    private function pendingRevisionFixture(EducationalCentre $centre, Folder $folder, Teacher $uploader): array
    {
        $document = new Document($folder, 'Acta');
        $file     = new DocumentFile(hash('sha256', uniqid('', true)), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);

        return [
            'centre'   => $centre,
            'folder'   => $folder,
            'revision' => $revision,
            'section'  => $folder->getDocumentSection(),
            'document' => $document,
            'file'     => $file,
        ];
    }

    public function testNotifiesATeacherHoldingTheFoldersReviewProfile(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $reviewer = $this->teacher('revisor');
        $assign   = new SpecificProfileAssignment($profile, null, $reviewer);
        $uploader = $this->teacher('subidor');
        $enabled  = $this->booleanDefinition('notifications.pending_review_reminder_enabled', 'true');
        $emailOn  = $this->booleanDefinition('notifications.email_notifications_enabled', 'true');
        $fixture  = $this->pendingRevisionFixture($centre, $folder, $uploader);

        $this->persist($centre, $section, $folder, $profile, $reviewer, $assign, $uploader, $enabled, $emailOn, $fixture['document'], $fixture['file'], $fixture['revision']);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->notifier($mailer)->notifyReviewers($fixture['revision']);
    }

    /**
     * The whole point of this feature: canManageFolder() (quality manager/admin) is deliberately
     * NOT consulted — only teachers who personally hold one of the folder's own review-profile
     * rows get notified.
     */
    public function testDoesNotNotifyAQualityManagerWhoDoesNotHoldAReviewProfile(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $uploader = $this->teacher('subidor');
        $fixture  = $this->pendingRevisionFixture($centre, $folder, $uploader);

        $this->persist($centre, $manager, $section, $folder, $profile, $uploader, $fixture['document'], $fixture['file'], $fixture['revision']);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->notifier($mailer)->notifyReviewers($fixture['revision']);
    }

    public function testDoesNotNotifyAReviewerWhoHasDisabledTheSetting(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $reviewer = $this->teacher('revisor');
        $assign   = new SpecificProfileAssignment($profile, null, $reviewer);
        $uploader = $this->teacher('subidor');
        $disabled = $this->booleanDefinition('notifications.pending_review_reminder_enabled', 'false');
        $fixture  = $this->pendingRevisionFixture($centre, $folder, $uploader);

        $this->persist($centre, $section, $folder, $profile, $reviewer, $assign, $uploader, $disabled, $fixture['document'], $fixture['file'], $fixture['revision']);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->notifier($mailer)->notifyReviewers($fixture['revision']);
    }

    public function testDoesNothingWhenTheFolderHasNoReviewProfile(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $uploader = $this->teacher('subidor');
        $fixture  = $this->pendingRevisionFixture($centre, $folder, $uploader);

        $this->persist($centre, $section, $folder, $uploader, $fixture['document'], $fixture['file'], $fixture['revision']);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->notifier($mailer)->notifyReviewers($fixture['revision']);
    }
}
