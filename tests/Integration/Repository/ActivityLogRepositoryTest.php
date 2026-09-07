<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\ActivityLogRepository;
use App\Tests\Integration\RepositoryTestCase;

final class ActivityLogRepositoryTest extends RepositoryTestCase
{
    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro ' . $code)->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', ucfirst($username))))->setUsername($username);
    }

    private function log(
        string $createdAt,
        string $actionType,
        ?Teacher $activeUser = null,
        ?EducationalCentre $centre = null,
        string $ip = '10.0.0.1',
    ): ActivityLog {
        return new ActivityLog(
            createdAt:         new \DateTimeImmutable($createdAt),
            ip:                $ip,
            actionType:        $actionType,
            activeUser:        $activeUser,
            educationalCentre: $centre,
        );
    }

    private function repo(): ActivityLogRepository
    {
        /** @var ActivityLogRepository $repo */
        $repo = self::getContainer()->get(ActivityLogRepository::class);

        return $repo;
    }

    public function testFiltersByDateRange(): void
    {
        $this->persist(
            $this->log('2026-01-01 10:00:00', 'session.login'),
            $this->log('2026-06-01 10:00:00', 'session.login'),
            $this->log('2026-12-01 10:00:00', 'session.login'),
        );

        $rows = $this->repo()->createFilteredQuery([
            'dateFrom' => '2026-03-01 00:00:00',
            'dateTo'   => '2026-09-01 00:00:00',
        ])->getResult();

        self::assertCount(1, $rows);
        self::assertSame('2026-06-01 10:00:00', $rows[0]->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testFiltersByUserQueryAgainstNameAndUsername(): void
    {
        $ana  = $this->teacher('ana');
        $luis = $this->teacher('luis');
        $this->persist(
            $ana,
            $luis,
            $this->log('2026-01-01 10:00:00', 'document.download', $ana),
            $this->log('2026-01-02 10:00:00', 'document.download', $luis),
        );

        $rows = $this->repo()->createFilteredQuery(['userQuery' => 'ana'])->getResult();

        self::assertCount(1, $rows);
        self::assertSame('ana', $rows[0]->getActiveUser()?->getUsername());
    }

    public function testFiltersByCentreAndActionType(): void
    {
        $a = $this->centre('11111111');
        $b = $this->centre('22222222');
        $this->persist(
            $a,
            $b,
            $this->log('2026-01-01 10:00:00', 'document.upload', null, $a),
            $this->log('2026-01-02 10:00:00', 'document.download', null, $a),
            $this->log('2026-01-03 10:00:00', 'document.upload', null, $b),
        );

        $byCentre = $this->repo()->createFilteredQuery(['centreId' => $a->getId()->toRfc4122()])->getResult();
        self::assertCount(2, $byCentre);

        $byAction = $this->repo()->createFilteredQuery(['actionType' => 'document.upload'])->getResult();
        self::assertCount(2, $byAction);
    }

    public function testSortsByCreatedAtDescendingByDefaultAndAscendingWhenAsked(): void
    {
        $this->persist(
            $this->log('2026-01-01 10:00:00', 'a'),
            $this->log('2026-02-01 10:00:00', 'b'),
        );

        $desc = $this->repo()->createFilteredQuery()->getResult();
        self::assertSame('b', $desc[0]->getActionType());

        $asc = $this->repo()->createFilteredQuery(['sort' => 'createdAt', 'sortDir' => 'asc'])->getResult();
        self::assertSame('a', $asc[0]->getActionType());
    }

    public function testFindDistinctActionTypes(): void
    {
        $this->persist(
            $this->log('2026-01-01 10:00:00', 'session.login'),
            $this->log('2026-01-02 10:00:00', 'session.login'),
            $this->log('2026-01-03 10:00:00', 'document.upload'),
        );

        self::assertSame(['document.upload', 'session.login'], $this->repo()->findDistinctActionTypes());
    }

    public function testDeleteOlderThan(): void
    {
        $this->persist(
            $this->log('2026-01-01 10:00:00', 'old'),
            $this->log('2026-06-01 10:00:00', 'recent'),
        );

        $deleted = $this->repo()->deleteOlderThan(new \DateTimeImmutable('2026-03-01 00:00:00'));

        self::assertSame(1, $deleted);
        $this->em->clear();
        $remaining = $this->repo()->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('recent', $remaining[0]->getActionType());
    }
}
