<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SettingFile;
use App\Repository\CentreSettingValueRepository;
use App\Repository\GlobalSettingValueRepository;
use App\Repository\TeacherSettingValueRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Borra un SettingFile si ya no está referenciado por ninguna fila de valor de
 * ajuste (global, de centro o de docente). Debe invocarse después de haber
 * flusheado el cambio que dejó de apuntar al fichero (reemplazo o borrado de
 * la referencia), para que el recuento refleje el estado ya actualizado.
 */
final class SettingFileGarbageCollector
{
    public function __construct(
        private readonly GlobalSettingValueRepository $globalValues,
        private readonly CentreSettingValueRepository $centreValues,
        private readonly TeacherSettingValueRepository $teacherValues,
        private readonly EntityManagerInterface $em,
    ) {}

    public function deleteIfOrphaned(SettingFile $file): void
    {
        $references = $this->globalValues->countByFile($file)
            + $this->centreValues->countByFile($file)
            + $this->teacherValues->countByFile($file);

        if ($references > 0) {
            return;
        }

        $this->em->remove($file);
        $this->em->flush();
    }
}
