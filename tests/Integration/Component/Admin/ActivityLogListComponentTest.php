<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component\Admin;

use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ActivityLogListComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username, bool $admin = false): Teacher
    {
        $teacher = (new Teacher(new PersonName('Nombre', ucfirst($username))))->setUsername($username);
        $teacher->setAdmin($admin);

        return $teacher;
    }

    private function log(string $createdAt, string $actionType, string $ip): ActivityLog
    {
        return new ActivityLog(new \DateTimeImmutable($createdAt), $ip, $actionType);
    }

    public function testMountDeniesANonAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $this->loginAs($teacher, $centre);

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Admin:ActivityLogListComponent', [], $this->client)->render();
    }

    public function testRendersRowsAndFiltersByActionType(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('root', admin: true);
        $this->persist(
            $centre,
            $admin,
            $this->log('2026-02-01 10:00:00', 'session.login', '198.51.100.1'),
            $this->log('2026-02-02 10:00:00', 'document.download', '203.0.113.222'),
        );
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:ActivityLogListComponent', [], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('198.51.100.1', $html);
        self::assertStringContainsString('203.0.113.222', $html);

        // Filtering to one action type must drop the other row (the IP only shows in a row cell).
        $filtered = $component->set('actionType', 'document.download')->render()->crawler()->html();
        self::assertStringContainsString('203.0.113.222', $filtered);
        self::assertStringNotContainsString('198.51.100.1', $filtered);
    }
}
