<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Tests\Integration\ControllerTestCase;

final class CentreProfileControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    public function testIndexDeniedWithoutSectionPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/perfiles");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSavingReplacesQualityManagersAndInternalAuditors(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $qmCandidate  = $this->teacher('futuro_calidad');
        $auditorCandidate = $this->teacher('futuro_auditor');
        $this->persist($centre, $admin, $qmCandidate, $auditorCandidate);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/perfiles");
        $this->client->request('POST', "/centro/{$centreId}/perfiles", [
            '_token'            => $this->csrfToken('edit_centre_profiles_' . $centreId),
            'quality_managers'  => [$qmCandidate->getId()->toRfc4122()],
            'internal_auditors' => [$auditorCandidate->getId()->toRfc4122()],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/perfiles"));

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres  = self::getContainer()->get(EducationalCentreRepository::class);
        $reloaded = $centres->findById($centreId);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getQualityManagers());
        self::assertCount(1, $reloaded->getInternalAuditors());
    }

    public function testSavingWithNoSelectionsClearsBothLists(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $existingQm = $this->teacher('ya_calidad');
        $centre->getQualityManagers()->add($existingQm);
        $this->persist($centre, $admin, $existingQm);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/perfiles");
        $this->client->request('POST', "/centro/{$centreId}/perfiles", [
            '_token' => $this->csrfToken('edit_centre_profiles_' . $centreId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/perfiles"));

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres  = self::getContainer()->get(EducationalCentreRepository::class);
        $reloaded = $centres->findById($centreId);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getQualityManagers());
    }

    public function testSaveRejectedWithInvalidCsrfToken(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/centro/{$centreId}/perfiles", [
            '_token' => 'invalido',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
