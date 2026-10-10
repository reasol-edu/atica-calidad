<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\RejectionReasonRepository;
use App\Tests\Integration\ControllerTestCase;

final class RejectionReasonControllerTest extends ControllerTestCase
{
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

    /** @return array{EducationalCentre, Teacher} */
    private function adminInCentre(): array
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $admin  = (new Teacher(new PersonName('Admin', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->persist($centre, $admin);
        $this->loginAs($admin, $centre);

        return [$centre, $admin];
    }

    public function testSavingKeepsTheCleanedLinesInOrder(): void
    {
        [$centre] = $this->adminInCentre();
        $id = $centre->getId()->toRfc4122();

        $this->client->request('GET', "/centro/{$id}/motivos-de-rechazo");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', "/centro/{$id}/motivos-de-rechazo/guardar", [
            '_token'  => $this->csrfToken('rejection_reasons_' . $id),
            'reasons' => "Falta el sello\n\n  Falta   la firma  \nFalta el sello\n",
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        /** @var RejectionReasonRepository $repo */
        $repo = self::getContainer()->get(RejectionReasonRepository::class);
        $this->em->clear();
        $stored = array_map(static fn ($r): string => $r->getText(), $repo->findByCentre($this->em->find(EducationalCentre::class, $centre->getId()) ?? $centre));
        self::assertSame(['Falta el sello', 'Falta la firma'], $stored);
    }

    public function testAnEmptyListGoesBackToTheStandardReasons(): void
    {
        [$centre] = $this->adminInCentre();
        $id = $centre->getId()->toRfc4122();
        $this->client->request('GET', "/centro/{$id}/motivos-de-rechazo");
        $this->client->request('POST', "/centro/{$id}/motivos-de-rechazo/guardar", ['_token' => $this->csrfToken('rejection_reasons_' . $id), 'reasons' => 'Uno']);
        $this->client->request('POST', "/centro/{$id}/motivos-de-rechazo/restablecer", ['_token' => $this->csrfToken('rejection_reasons_' . $id)]);

        /** @var RejectionReasonRepository $repo */
        $repo = self::getContainer()->get(RejectionReasonRepository::class);
        $this->em->clear();
        self::assertSame([], $repo->findByCentre($this->em->find(EducationalCentre::class, $centre->getId()) ?? $centre));
    }

    public function testAPlainTeacherCannotSeeThePage(): void
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $teacher = (new Teacher(new PersonName('Doc', 'Docente')))->setUsername('docente');
        $this->persist($centre, $teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/motivos-de-rechazo');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
