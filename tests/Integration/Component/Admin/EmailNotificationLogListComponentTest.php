<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component\Admin;

use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class EmailNotificationLogListComponentTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function log(EducationalCentre $centre, string $recipientName, string $eventKey, string $subject, bool $success, string $sentAt): EmailNotificationLog
    {
        return new EmailNotificationLog($centre, null, $recipientName, $eventKey, $subject, $success, $success ? null : 'Error de envío', new \DateTimeImmutable($sentAt));
    }

    public function testSearchFiltersByRecipientNameOrSubject(): void
    {
        $centre    = $this->centre();
        $matching  = $this->log($centre, 'Ana García', 'password_reset', 'Restablecer contraseña', true, '2025-09-15 10:00:00');
        $other     = $this->log($centre, 'Luis Pérez', 'email_verification', 'Verifica tu email', true, '2025-09-15 11:00:00');
        $admin     = $this->teacher('director');
        $this->persist($centre, $matching, $other, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:EmailNotificationLogListComponent', ['centre' => $centre], $this->client);
        $component->set('search', 'García');

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Ana García', $html);
        self::assertStringNotContainsString('Luis Pérez', $html);
    }

    public function testStatusFilterNarrowsToFailedOrSuccessfulOnly(): void
    {
        $centre  = $this->centre();
        $ok      = $this->log($centre, 'Ana García', 'password_reset', 'Correcto', true, '2025-09-15 10:00:00');
        $failed  = $this->log($centre, 'Luis Pérez', 'password_reset', 'Fallido', false, '2025-09-15 11:00:00');
        $admin   = $this->teacher('director');
        $this->persist($centre, $ok, $failed, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:EmailNotificationLogListComponent', ['centre' => $centre], $this->client);
        $component->set('status', 'failed');

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Luis Pérez', $html);
        self::assertStringNotContainsString('Ana García', $html);
    }

    public function testEventKeyFilterNarrowsToASingleEventType(): void
    {
        $centre       = $this->centre();
        $passwordLog  = $this->log($centre, 'Ana García', 'password_reset', 'Restablecer', true, '2025-09-15 10:00:00');
        $verifyLog    = $this->log($centre, 'Luis Pérez', 'email_verification', 'Verificar', true, '2025-09-15 11:00:00');
        $admin        = $this->teacher('director');
        $this->persist($centre, $passwordLog, $verifyLog, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:EmailNotificationLogListComponent', ['centre' => $centre], $this->client);
        $component->set('eventKey', 'email_verification');

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Luis Pérez', $html);
        self::assertStringNotContainsString('Ana García', $html);
    }

    public function testGetDistinctEventKeysListsEachEventKeyOnce(): void
    {
        $centre = $this->centre();
        $first  = $this->log($centre, 'Ana García', 'password_reset', 'A', true, '2025-09-15 10:00:00');
        $second = $this->log($centre, 'Luis Pérez', 'password_reset', 'B', true, '2025-09-15 11:00:00');
        $third  = $this->log($centre, 'Marta Ruiz', 'email_verification', 'C', true, '2025-09-15 12:00:00');
        $admin  = $this->teacher('director');
        $this->persist($centre, $first, $second, $third, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:EmailNotificationLogListComponent', ['centre' => $centre], $this->client);
        /** @var \App\Twig\Components\Admin\EmailNotificationLogListComponent $instance */
        $instance = $component->component();

        self::assertSame(['email_verification', 'password_reset'], $instance->getDistinctEventKeys());
    }

    public function testHasActiveFiltersReflectsWhetherAnyFilterIsSet(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:EmailNotificationLogListComponent', ['centre' => $centre], $this->client);
        /** @var \App\Twig\Components\Admin\EmailNotificationLogListComponent $instance */
        $instance = $component->component();
        self::assertFalse($instance->hasActiveFilters());

        $component->set('search', 'algo');
        /** @var \App\Twig\Components\Admin\EmailNotificationLogListComponent $instance */
        $instance = $component->component();
        self::assertTrue($instance->hasActiveFilters());
    }

    public function testClearFiltersResetsEveryFilterAndThePage(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:EmailNotificationLogListComponent', ['centre' => $centre], $this->client);
        $component->set('search', 'algo');
        $component->set('eventKey', 'password_reset');
        $component->set('status', 'failed');
        $component->set('page', 3);

        $component->call('clearFilters');

        /** @var \App\Twig\Components\Admin\EmailNotificationLogListComponent $instance */
        $instance = $component->component();
        self::assertFalse($instance->hasActiveFilters());
        self::assertSame(1, $instance->page);
    }

    public function testQuickRangeSetsDateFromAndDateToRelativeToNow(): void
    {
        self::mockTime('2025-09-20 12:00:00');

        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:EmailNotificationLogListComponent', ['centre' => $centre], $this->client);
        $component->call('quickRange', ['range' => 'last_24h']);

        /** @var \App\Twig\Components\Admin\EmailNotificationLogListComponent $instance */
        $instance = $component->component();
        self::assertSame('2025-09-19T12:00', $instance->dateFrom);
        self::assertSame('2025-09-20T12:00', $instance->dateTo);
    }

    public function testDateRangeFilterNarrowsToLogsSentWithinTheRange(): void
    {
        $centre  = $this->centre();
        $inRange = $this->log($centre, 'Ana García', 'password_reset', 'Dentro', true, '2025-09-15 10:00:00');
        $outside = $this->log($centre, 'Luis Pérez', 'password_reset', 'Fuera', true, '2025-01-01 10:00:00');
        $admin   = $this->teacher('director');
        $this->persist($centre, $inRange, $outside, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:EmailNotificationLogListComponent', ['centre' => $centre], $this->client);
        $component->set('dateFrom', '2025-09-01T00:00');
        $component->set('dateTo', '2025-09-30T00:00');

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Ana García', $html);
        self::assertStringNotContainsString('Luis Pérez', $html);
    }
}
