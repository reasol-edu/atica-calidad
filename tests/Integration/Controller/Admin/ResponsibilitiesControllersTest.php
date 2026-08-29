<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\ListItemRepository;
use App\Tests\Integration\ControllerTestCase;

/** Covers the thin controller shells around Responsabilidades' Live Components: permission gating and (for ListItemController) the JSON export/import endpoints. */
final class ResponsibilitiesControllersTest extends ControllerTestCase
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

    public function testHubDeniedWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testHubRendersForAQualityManager(): void
    {
        $centre = $this->centre();
        $qm     = $this->teacher('calidad');
        $centre->getQualityManagers()->add($qm);
        $this->persist($centre, $qm);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($qm, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testListsPageDeniedWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSpecificProfilesPageDeniedWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/perfiles-especificos");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAssignmentsPageDeniedWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/asignar-perfiles");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testExportProducesADownloadableJsonAttachment(): void
    {
        $centre = $this->centre();
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo');
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/exportar");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $contentDisposition = $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertNotNull($contentDisposition);
        self::assertStringContainsString('attachment', $contentDisposition);
        $body = (string) $this->client->getResponse()->getContent();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('list_items', $decoded['type']);
    }

    public function testImportRoundTripsAPreviouslyExportedFile(): void
    {
        $centre = $this->centre();
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo original');
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/exportar");
        $exported = (string) $this->client->getResponse()->getContent();

        $path = tempnam(sys_get_temp_dir(), 'list_items_export_');
        self::assertNotFalse($path);
        file_put_contents($path, $exported);
        $upload = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'listas.json', 'application/json', null, true);

        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar",
            ['_token' => $this->csrfToken('import_list_items')],
            ['json' => $upload],
        );

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/responsabilidades/listas"));

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Grupo original', $roots[0]->getName());
    }

    public function testImportRejectsAMalformedFileWithAFlashInsteadOfCrashing(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $path = tempnam(sys_get_temp_dir(), 'bad_import_');
        self::assertNotFalse($path);
        file_put_contents($path, 'esto no es json');
        $upload = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'malo.json', 'application/json', null, true);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar",
            ['_token' => $this->csrfToken('import_list_items')],
            ['json' => $upload],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
