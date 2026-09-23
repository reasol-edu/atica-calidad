<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\FolderRepository;
use App\Service\FolderZipExporter;
use App\Tests\Integration\RepositoryTestCase;

/**
 * A folder's ZIP must not hold its files' contents in memory together — measured 2026-09 at ~3×
 * the folder's size before (60 MB of files needed ~180 MB, past the binary's default 128 MB
 * memory_limit). Now it streams one file at a time, so the extra memory stays roughly flat
 * whatever the folder's size.
 */
final class FolderZipExporterMemoryTest extends RepositoryTestCase
{
    private const int FILES     = 40;
    private const int FILE_SIZE = 1_500_000;

    public function testExportingALargeFolderKeepsMemoryFlat(): void
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $teacher = (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername('docente');
        $this->persist($centre, $section, $folder, $teacher);
        $folderId  = $folder->getId()->toRfc4122();
        $teacherId = $teacher->getId();

        // Persisted one by one, clearing each time, so the setup itself doesn't keep them all.
        for ($i = 0; $i < self::FILES; ++$i) {
            $folder  = $this->em->getReference(Folder::class, $folder->getId());
            $teacher = $this->em->getReference(Teacher::class, $teacherId);
            $content  = random_bytes(self::FILE_SIZE);
            $document = new Document($folder, 'Documento ' . $i);
            $file     = new DocumentFile(hash('sha256', $content), $content, 'application/pdf', 'f.pdf', self::FILE_SIZE);
            $revision = new DocumentRevision($document, 1, $file, false, $teacher);
            $document->getRevisions()->add($revision);
            $document->setActiveRevision($revision);
            $this->persist($file, $document, $revision);
            $this->em->clear();
            unset($content, $document, $file, $revision);
        }
        gc_collect_cycles();

        /** @var FolderRepository $folders */
        $folders  = self::getContainer()->get(FolderRepository::class);
        $reloaded = $folders->findById($folderId);
        self::assertNotNull($reloaded);
        /** @var FolderZipExporter $exporter */
        $exporter = self::getContainer()->get(FolderZipExporter::class);

        $before = memory_get_usage();
        memory_reset_peak_usage();
        $response = $exporter->export($reloaded);
        $extra    = memory_get_peak_usage() - $before;

        $zipPath = $response->getFile()->getPathname();
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($zipPath));
            self::assertSame(self::FILES, $zip->numFiles);
            $zip->close();
        } finally {
            unlink($zipPath);
        }

        // 60 MB of files; a few files' worth of headroom, far from the ~180 MB it used to take.
        self::assertLessThan(20_000_000, $extra, \sprintf('exporting used %.1f MB of extra memory', $extra / 1e6));
    }
}
