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

    public function testListsPageShowsEachRootWithItsElementCount(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $child  = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $child->setParent($root);
        $grand = (new ListItem())->setEducationalCentre($centre)->setName('Álgebra');
        $grand->setParent($child);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $root, $child, $grand, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $crawler = $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Departamento', $crawler->text());
        self::assertStringContainsString('2 elementos', $crawler->text());
        self::assertStringNotContainsString('Álgebra', $crawler->text(), 'the elements are managed on the list\'s own screen');
    }

    public function testCreateListAddsARootAndOpensItsScreen(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas");
        $this->client->request('POST', "/centro/{$centreId}/responsabilidades/listas/nueva", [
            '_token' => $this->csrfToken('list_create'),
            'name'   => 'Tutorías',
        ]);

        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $this->em->clear();
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Tutorías', $roots[0]->getName());
        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/responsabilidades/listas/" . $roots[0]->getId()->toRfc4122()));
    }

    public function testMovingAListSwapsItsPositionWithTheNeighbour(): void
    {
        $centre = $this->centre();
        $first  = (new ListItem())->setEducationalCentre($centre)->setName('A')->setPosition(0);
        $second = (new ListItem())->setEducationalCentre($centre)->setName('B')->setPosition(1);
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $first, $second, $admin);
        $centreId = $centre->getId()->toRfc4122();
        $secondId = $second->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas");
        $this->client->request('POST', "/centro/{$centreId}/responsabilidades/listas/{$secondId}/subir", [
            '_token' => $this->csrfToken('list_move'),
        ]);

        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $this->em->clear();
        self::assertSame(['B', 'A'], array_map(static fn (ListItem $r): string => $r->getName(), $items->findRootsByCentre($centre)));
    }

    public function testManageListRendersTheRootsElementsOnly(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $child  = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $child->setParent($root);
        $other      = (new ListItem())->setEducationalCentre($centre)->setName('Etapa');
        $otherChild = (new ListItem())->setEducationalCentre($centre)->setName('Primaria');
        $otherChild->setParent($other);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $root, $child, $other, $otherChild, $admin);
        $centreId = $centre->getId()->toRfc4122();
        $rootId   = $root->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $crawler = $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/{$rootId}");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Matemáticas', $crawler->text());
        self::assertStringNotContainsString('Primaria', $crawler->text());
    }

    public function testManageListIs404ForANonRootItemAndForAnotherCentresRoot(): void
    {
        $centre      = $this->centre();
        $otherCentre = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Ciudad');
        $root        = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $child       = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $child->setParent($root);
        $foreign = (new ListItem())->setEducationalCentre($otherCentre)->setName('Ajena');
        $admin   = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $otherCentre, $root, $child, $foreign, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/" . $child->getId()->toRfc4122());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/" . $foreign->getId()->toRfc4122());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
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
