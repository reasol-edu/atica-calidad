<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\AcademicYearRepository;
use App\Repository\EducationalCentreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

final class TenantContext implements TenantContextInterface, ResetInterface
{
    private const SESSION_KEY      = 'tenant.centre_id';
    private const SESSION_YEAR_KEY = 'tenant.year_id';

    // Memoizes the resolved centre for the duration of the request: getSelectedCentre()
    // is invoked many times per request (subscriber, controllers, Twig
    // extensions) and each call used to repeat the query with a JOIN plus a
    // conditional refresh(). $centreResolved distinguishes "not yet resolved" from
    // "resolved to null" (session with an id that no longer exists). reset() clears the
    // cache in case the PHP process ends up being reused between requests (e.g. FrankenPHP
    // in worker mode); with the current deployment (php_server without worker) each
    // request already rebuilds the container, so this is a safety net.
    private bool $centreResolved = false;
    private ?EducationalCentre $resolvedCentre = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EducationalCentreRepository $centres,
        private readonly AcademicYearRepository $years,
        private readonly EntityManagerInterface $em,
    ) {}

    public function isSelected(): bool
    {
        return $this->requestStack->getSession()->has(self::SESSION_KEY);
    }

    public function getSelectedCentre(): ?EducationalCentre
    {
        if ($this->centreResolved) {
            return $this->resolvedCentre;
        }

        $id = $this->requestStack->getSession()->get(self::SESSION_KEY);
        if (!\is_string($id)) {
            return null;
        }

        $centre = $this->centres->findByIdWithActiveYear($id);

        // Ensure activeAcademicYear is not stale from a prior identity-map load
        // (e.g. the subscriber loaded the centre without the JOIN in the same request)
        if ($centre !== null && $this->em->getUnitOfWork()->isInIdentityMap($centre)) {
            $this->em->refresh($centre);
        }

        $this->resolvedCentre = $centre;
        $this->centreResolved = true;

        return $centre;
    }

    public function selectCentre(EducationalCentre $centre): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $centre->getId()->toRfc4122());
        $this->resolvedCentre = $centre;
        $this->centreResolved = true;
        $this->clearYear();
    }

    public function getViewYear(EducationalCentre $centre): ?AcademicYear
    {
        $id = $this->requestStack->getSession()->get(self::SESSION_YEAR_KEY);
        if (!\is_string($id)) {
            return $centre->getActiveAcademicYear();
        }

        $year = $this->years->findByCentreAndId($centre, $id);

        return $year ?? $centre->getActiveAcademicYear();
    }

    public function selectYear(AcademicYear $year): void
    {
        $this->requestStack->getSession()->set(self::SESSION_YEAR_KEY, $year->getId()->toRfc4122());
    }

    public function clearYear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_YEAR_KEY);
    }

    public function isViewingNonActiveYear(EducationalCentre $centre): bool
    {
        $activeYear = $centre->getActiveAcademicYear();
        if ($activeYear === null) {
            return false;
        }

        $viewYear = $this->getViewYear($centre);
        if ($viewYear === null) {
            return false;
        }

        return $viewYear->getId()->toRfc4122() !== $activeYear->getId()->toRfc4122();
    }

    public function canSwitchCentre(Teacher $teacher): bool
    {
        return \count($this->centres->findAccessibleByTeacher($teacher)) > 1;
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
        $this->resolvedCentre = null;
        $this->centreResolved = false;
    }

    public function reset(): void
    {
        $this->resolvedCentre = null;
        $this->centreResolved = false;
    }
}
