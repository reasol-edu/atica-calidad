<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\QualityAttachment;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Service\DocumentTextIndexer;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Search inside the documents ("En el contenido" of the ⌘K palette). The point of most of these tests is what must NOT turn up:
 * a result, and above all a snippet of its text, never reaches someone who could not open the document.
 */
final class ContentSearchTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private DocumentSection $section;
    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year          = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($year);
        $this->section = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección')->setPosition(0);
        $this->folder  = (new Folder())->setDocumentSection($this->section)->setName('Carpeta')->setPosition(0);
        $this->persist($this->centre, $year, $this->section, $this->folder);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    /** A document whose revision in force is a plain-text file with $text, read into the index. */
    private function document(Folder $folder, Teacher $by, string $name, string $text, bool $indexed = true): Document
    {
        $document = new Document($folder, $name);
        $file     = new DocumentFile(hash('sha256', $name . $text . random_int(1, PHP_INT_MAX)), $text, 'text/plain', 'doc.txt', \strlen($text));
        $revision = new DocumentRevision($document, 1, $file, false, $by);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($by, $file, $document, $revision);
        if ($indexed) {
            $this->index($file);
        }

        return $document;
    }

    private function index(DocumentFile $file): void
    {
        /** @var DocumentTextIndexer $indexer */
        $indexer = self::getContainer()->get(DocumentTextIndexer::class);
        $indexer->index($file->getId());
    }

    /** @return array<int|string, mixed> the "groups" object of the response */
    private function search(string $q, bool $past = false): array
    {
        $this->client->request('GET', '/buscar?q=' . urlencode($q) . ($past ? '&past=1' : ''));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['groups']);

        return $data['groups'];
    }

    /**
     * @param array<int|string, mixed> $groups
     *
     * @return list<mixed> the labels of the "En el contenido" results
     */
    private function contentLabels(array $groups): array
    {
        $items = $groups['content'] ?? [];
        self::assertIsArray($items);

        return array_values(array_map(static fn (mixed $i): mixed => \is_array($i) ? $i['label'] : null, $items));
    }

    private function responseBody(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    public function testADocumentIsFoundByWhatIsWrittenInsideItWithTheMatchMarked(): void
    {
        $teacher = $this->teacher('docente');
        $this->document($this->folder, $teacher, 'Acta 3', 'En la reunión se aprobó el Protocolo de Convivencia del centro para la Educación Física.');

        $this->loginAs($teacher, $this->centre);
        $groups = $this->search('educacion fisica');

        self::assertSame(['Acta 3'], $this->contentLabels($groups));
        $item = $groups['content'][0];
        self::assertIsArray($item);
        self::assertStringContainsString('/arbol-documental', (string) $item['url']);
        $marked = array_values(array_map(static fn (array $p): string => $p[0], array_filter($item['snippet'], static fn (array $p): bool => $p[1])));
        // The words come back as written in the document, accents and capitals included.
        self::assertSame(['Educación', 'Física'], $marked);
    }

    public function testEveryWordOfTheQueryHasToBeInTheDocument(): void
    {
        $teacher = $this->teacher('docente');
        $this->document($this->folder, $teacher, 'Acta', 'El protocolo de convivencia.');

        $this->loginAs($teacher, $this->centre);

        self::assertSame(['Acta'], $this->contentLabels($this->search('protocolo convivencia')));
        self::assertSame([], $this->contentLabels($this->search('protocolo inexistente')));
    }

    public function testWordsOfTwoLettersAreNotSearchedInsideDocuments(): void
    {
        $teacher = $this->teacher('docente');
        $this->document($this->folder, $teacher, 'Acta', 'El protocolo de convivencia.');

        $this->loginAs($teacher, $this->centre);

        self::assertSame([], $this->contentLabels($this->search('de el')));
    }

    public function testAPercentSignInTheQueryIsLiteral(): void
    {
        $teacher = $this->teacher('docente');
        $this->document($this->folder, $teacher, 'Con porcentaje', 'Se alcanzó el 50% de la meta.');
        $this->document($this->folder, $teacher, 'Sin porcentaje', 'Se alcanzó el 5000 de la meta.');

        $this->loginAs($teacher, $this->centre);

        self::assertSame(['Con porcentaje'], $this->contentLabels($this->search('50%')));
    }

    public function testAFileNotReadYetIsNotSearched(): void
    {
        $teacher = $this->teacher('docente');
        $this->document($this->folder, $teacher, 'Sin leer', 'Protocolo secreto de evacuación.', indexed: false);

        $this->loginAs($teacher, $this->centre);

        self::assertSame([], $this->contentLabels($this->search('evacuación')));
    }

    public function testAFolderRestrictedToAProfileHidesItsContentAndItsSnippetFromEveryoneElse(): void
    {
        $profile = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura');
        $this->folder->addVisibilityProfile($profile);
        $author   = $this->teacher('autor');
        $stranger = $this->teacher('ajeno');
        $this->persist($profile, $stranger);
        $this->document($this->folder, $author, 'Informe reservado', 'Contenido muy RESERVADO del expediente disciplinario.');

        $this->loginAs($stranger, $this->centre);
        $groups = $this->search('reservado expediente');

        self::assertArrayNotHasKey('content', $groups);
        self::assertArrayNotHasKey('documents', $groups);
        self::assertStringNotContainsString('RESERVADO', $this->responseBody());
        self::assertStringNotContainsString('Informe reservado', $this->responseBody());
    }

    public function testASubmissionOfAHiddenActivityIsOnlyFoundByWhoEditsActivities(): void
    {
        $category = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 6)->setFolder($this->folder)->setHidden(true);
        $author   = $this->teacher('autor');
        $other    = $this->teacher('otro');
        $admin    = $this->teacher('director');
        $this->centre->getAdmins()->add($admin);
        $this->persist($category, $activity, $other, $admin);
        $this->document($this->folder, $author, 'Memoria de dpto', 'Autoevaluación confidencial del departamento.');

        $this->loginAs($other, $this->centre);
        self::assertSame([], $this->contentLabels($this->search('autoevaluación confidencial')));
        self::assertStringNotContainsString('confidencial', $this->responseBody());

        $this->loginAs($admin, $this->centre);
        self::assertSame(['Memoria de dpto'], $this->contentLabels($this->search('autoevaluación confidencial')));
    }

    public function testADocumentInTheTrashIsNotFound(): void
    {
        $teacher  = $this->teacher('docente');
        $document = $this->document($this->folder, $teacher, 'Borrado', 'Texto del documento eliminado.');
        $document->moveToTrash(new \DateTimeImmutable('2026-11-01'), $teacher);
        $this->flush();

        $this->loginAs($teacher, $this->centre);

        self::assertSame([], $this->contentLabels($this->search('eliminado')));
    }

    public function testADocumentWhoseOnlyRevisionIsStillPendingIsNotSearched(): void
    {
        $teacher  = $this->teacher('docente');
        $document = new Document($this->folder, 'Pendiente');
        $text     = 'Borrador pendiente de visto bueno.';
        $file     = new DocumentFile(hash('sha256', $text), $text, 'text/plain', 'p.txt', \strlen($text));
        $revision = new DocumentRevision($document, 1, $file, true, $teacher);
        $document->getRevisions()->add($revision);
        $this->persist($teacher, $file, $document, $revision);
        $this->index($file);

        $this->loginAs($teacher, $this->centre);

        self::assertSame([], $this->contentLabels($this->search('borrador pendiente')));
    }

    public function testOnlyTheRevisionInForceIsSearched(): void
    {
        $teacher  = $this->teacher('docente');
        $document = $this->document($this->folder, $teacher, 'Versionado', 'Versión antigua con la palabra alfabeto.');
        $text     = 'Versión nueva con la palabra abecedario.';
        $file     = new DocumentFile(hash('sha256', $text), $text, 'text/plain', 'v2.txt', \strlen($text));
        $second   = new DocumentRevision($document, 2, $file, false, $teacher);
        $document->getRevisions()->add($second);
        $document->setActiveRevision($second);
        $this->persist($file, $second);
        $this->index($file);

        $this->loginAs($teacher, $this->centre);

        self::assertSame(['Versionado'], $this->contentLabels($this->search('abecedario')));
        self::assertSame([], $this->contentLabels($this->search('alfabeto')));
    }

    public function testTheSameFileInTwoCentresOnlyShowsUpThroughTheViewersOwnCentre(): void
    {
        $teacher = $this->teacher('docente');
        $doc     = $this->document($this->folder, $teacher, 'Documento del centro A', 'Plan compartido de acogida del alumnado.');
        $file    = $doc->getActiveRevision()?->getFile();
        self::assertNotNull($file);

        // Another centre stores the very same file (same hash: one DocumentFile, one text row).
        $other        = (new EducationalCentre())->setCode('87654321')->setName('Centro B')->setCity('Otra');
        $otherSection = (new DocumentSection())->setEducationalCentre($other)->setName('Sección B')->setPosition(0);
        $otherFolder  = (new Folder())->setDocumentSection($otherSection)->setName('Carpeta B')->setPosition(0);
        $otherDoc     = new Document($otherFolder, 'Documento del centro B');
        $otherRev     = new DocumentRevision($otherDoc, 1, $file, false, $teacher);
        $otherDoc->getRevisions()->add($otherRev);
        $otherDoc->setActiveRevision($otherRev);
        $this->persist($other, $otherSection, $otherFolder, $otherDoc, $otherRev);

        $this->loginAs($teacher, $this->centre);

        self::assertSame(['Documento del centro A'], $this->contentLabels($this->search('acogida')));
        self::assertStringNotContainsString('centro B', $this->responseBody());
    }

    public function testASubmissionOfAnEarlierCourseNeedsTheSwitchAndShowsItsCourse(): void
    {
        self::mockTime('2026-11-10 10:00:00');
        $category = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Programación')->setStart(1, 9)->setEnd(30, 6)->setFolder($this->folder);
        $teacher  = $this->teacher('docente');
        $this->persist($category, $activity);
        $current = $this->document($this->folder, $teacher, 'Programación actual', 'Metodología activa y trabajo cooperativo.');
        $past    = $this->document($this->folder, $teacher, 'Programación pasada', 'Metodología tradicional y trabajo cooperativo.');
        $current->setActivityCycleYear(2026);
        $past->setActivityCycleYear(2025);
        $this->flush();

        $this->loginAs($teacher, $this->centre);

        self::assertSame(['Programación actual'], $this->contentLabels($this->search('cooperativo')));

        $groups = $this->search('cooperativo', past: true);
        self::assertSame(['Programación actual', 'Programación pasada'], $this->contentLabels($groups));
        $items = $groups['content'];
        self::assertIsArray($items);
        self::assertIsArray($items[1]);
        self::assertStringContainsString('2025-2026', (string) $items[1]['sublabel']);
        // A past submission opens in the document tree, which switches the folder to that course; Actividades only lists the current one.
        self::assertStringContainsString('/arbol-documental', (string) $items[1]['url']);
        self::assertStringContainsString('highlight=' . $past->getId()->toRfc4122(), (string) $items[1]['url']);
        // The current one keeps linking to Actividades, as in the name search.
        self::assertIsArray($items[0]);
        self::assertStringContainsString('/actividades', (string) $items[0]['url']);
    }

    public function testAnObsoleteFolderIsOnlySearchedWithTheSwitchAndOnlyForWhoManagesDocuments(): void
    {
        $this->folder->setObsolete(true);
        $manager = $this->teacher('calidad');
        $this->centre->getQualityManagers()->add($manager);
        $plain = $this->teacher('docente');
        $this->persist($manager, $plain);
        $this->document($this->folder, $manager, 'Procedimiento retirado', 'Instrucción derogada sobre excursiones.');

        $this->loginAs($manager, $this->centre);
        self::assertSame([], $this->contentLabels($this->search('derogada')));
        self::assertSame(['Procedimiento retirado'], $this->contentLabels($this->search('derogada', past: true)));

        $this->loginAs($plain, $this->centre);
        self::assertSame([], $this->contentLabels($this->search('derogada', past: true)));
        self::assertStringNotContainsString('derogada', $this->responseBody());
    }

    public function testAFileAttachedToAFindingIsOnlyFoundByWhoCanOpenTheFinding(): void
    {
        $reporter = $this->teacher('informante');
        $stranger = $this->teacher('ajeno');
        $manager  = $this->teacher('calidad');
        $this->centre->getQualityManagers()->add($manager);
        $finding = new Finding($this->centre, 'Humedad en el aula', 'Mancha en el techo', $reporter, new \DateTimeImmutable('2026-11-01'));
        $text    = 'Informe técnico: filtración en la cubierta del edificio B.';
        $file    = new DocumentFile(hash('sha256', $text), $text, 'text/plain', 'informe.txt', \strlen($text));
        $this->persist($reporter, $stranger, $manager, $finding, $file);
        $this->persist(QualityAttachment::forFinding($finding, $file, 'informe.txt', $reporter, new \DateTimeImmutable('2026-11-02')));
        $this->index($file);

        $this->loginAs($stranger, $this->centre);
        self::assertSame([], $this->contentLabels($this->search('filtración cubierta')));
        self::assertStringNotContainsString('filtración', $this->responseBody());

        foreach ([$reporter, $manager] as $allowed) {
            $this->loginAs($allowed, $this->centre);
            $groups = $this->search('filtración cubierta');
            self::assertSame(['informe.txt'], $this->contentLabels($groups));
            $item = $groups['content'][0];
            self::assertIsArray($item);
            self::assertStringContainsString('Humedad en el aula', (string) $item['sublabel']);
            self::assertStringContainsString('/mejora/fichas/' . $finding->getId()->toRfc4122(), (string) $item['url']);
        }
    }
}
