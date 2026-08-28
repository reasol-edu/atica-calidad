<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentFile;
use App\Repository\DocumentRevisionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Borra un DocumentFile si ya no está referenciado por ninguna revisión. Debe invocarse después de
 * haber flusheado el cambio que dejó de apuntar al fichero (reemplazo o borrado de la revisión),
 * para que el recuento refleje el estado ya actualizado — mismo patrón que
 * SettingFileGarbageCollector (src/Service/SettingFileGarbageCollector.php).
 */
final class DocumentFileGarbageCollector
{
    public function __construct(
        private readonly DocumentRevisionRepository $revisions,
        private readonly EntityManagerInterface $em,
    ) {}

    public function deleteIfOrphaned(DocumentFile $file): void
    {
        if ($this->revisions->countByFile($file) > 0) {
            return;
        }

        $this->em->remove($file);
        $this->em->flush();
    }
}
