<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\ActivityLog;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\ActivityLogRepository;
use App\Service\ActivityLogger;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ActivityLoggerTest extends RepositoryTestCase
{
    private string $previousAppLog = 'false';

    protected function setUp(): void
    {
        $this->previousAppLog = (string) ($_SERVER['APP_LOG'] ?? 'false');
        $_ENV['APP_LOG']      = $_SERVER['APP_LOG'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV['APP_LOG'] = $_SERVER['APP_LOG'] = $this->previousAppLog;
    }

    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro ' . $code)->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', ucfirst($username))))->setUsername($username);
    }

    private function auditDefinition(string $default = 'true'): SettingDefinition
    {
        return (new SettingDefinition())
            ->setKey('audit.log_enabled')
            ->setType(SettingType::Boolean)
            ->setDefaultValue($default)
            ->setGlobalScope(true)
            ->setCentreScope(true);
    }

    private function logger(): ActivityLogger
    {
        /** @var ActivityLogger $logger */
        $logger = self::getContainer()->get(ActivityLogger::class);

        return $logger;
    }

    private function pushRequest(string $ip = '203.0.113.9'): void
    {
        /** @var RequestStack $stack */
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push(Request::create('/x', 'POST', server: ['REMOTE_ADDR' => $ip]));
    }

    private function setToken(Teacher $user, ?Teacher $impersonator = null): void
    {
        /** @var TokenStorageInterface $storage */
        $storage = self::getContainer()->get(TokenStorageInterface::class);

        $token = new UsernamePasswordToken($user, 'main', ['ROLE_TEACHER']);
        if ($impersonator !== null) {
            $token = new SwitchUserToken($user, 'main', ['ROLE_TEACHER'], new UsernamePasswordToken($impersonator, 'main', ['ROLE_ADMIN']));
        }

        $storage->setToken($token);
    }

    /** @return ActivityLog[] */
    private function allLogs(): array
    {
        $this->em->clear();
        /** @var ActivityLogRepository $repo */
        $repo = self::getContainer()->get(ActivityLogRepository::class);

        return $repo->findAll();
    }

    public function testRecordsAnEntryWithIpUserAndCentre(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('ana');
        $this->persist($this->auditDefinition(), $centre, $teacher);

        $this->pushRequest('203.0.113.9');
        $this->setToken($teacher);

        $logger = $this->logger();
        $logger->record('document.download', ['document' => 'PR-01'], $centre);
        self::assertTrue($logger->hasExplicitEntries());
        $logger->flush();

        $logs = $this->allLogs();
        self::assertCount(1, $logs);
        self::assertSame('document.download', $logs[0]->getActionType());
        self::assertSame('203.0.113.9', $logs[0]->getIp());
        self::assertSame('ana', $logs[0]->getActiveUser()?->getUsername());
        self::assertNull($logs[0]->getRealUser());
        self::assertSame($centre->getId()->toRfc4122(), $logs[0]->getEducationalCentre()?->getId()->toRfc4122());
        self::assertSame(['document' => 'PR-01'], $logs[0]->getData());
    }

    public function testRecordsTheRealUserBehindAnImpersonation(): void
    {
        $centre       = $this->centre();
        $impersonated = $this->teacher('ana');
        $admin        = $this->teacher('director');
        $this->persist($this->auditDefinition(), $centre, $impersonated, $admin);

        $this->pushRequest();
        $this->setToken($impersonated, $admin);

        $logger = $this->logger();
        $logger->record('folder.open', [], $centre);
        $logger->flush();

        $logs = $this->allLogs();
        self::assertCount(1, $logs);
        self::assertSame('ana', $logs[0]->getActiveUser()?->getUsername());
        self::assertSame('director', $logs[0]->getRealUser()?->getUsername());
    }

    public function testDoesNothingWhenTheCentreSettingIsDisabled(): void
    {
        $centre = $this->centre();
        $def    = $this->auditDefinition('true');
        $off    = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('false');
        $teacher = $this->teacher('ana');
        $this->persist($def, $centre, $off, $teacher);

        $this->pushRequest();
        $this->setToken($teacher);

        $logger = $this->logger();
        $logger->record('document.download', [], $centre);
        $logger->flush();

        self::assertCount(0, $this->allLogs());
    }

    public function testStillRecordsForACentreWhoseSettingIsOnWhenAnotherCentreIsOff(): void
    {
        $on     = $this->centre('11111111');
        $off    = $this->centre('22222222');
        $def    = $this->auditDefinition('true');
        $offVal = (new CentreSettingValue())->setDefinition($def)->setCentre($off)->setValue('false');
        $teacher = $this->teacher('ana');
        $this->persist($def, $on, $off, $offVal, $teacher);

        $this->pushRequest();
        $this->setToken($teacher);

        $logger = $this->logger();
        $logger->record('document.download', [], $off);
        $logger->record('document.download', [], $on);
        $logger->flush();

        $logs = $this->allLogs();
        self::assertCount(1, $logs);
        self::assertSame($on->getId()->toRfc4122(), $logs[0]->getEducationalCentre()?->getId()->toRfc4122());
    }
}
