<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SettingFile;
use App\Repository\CentreSettingValueRepository;
use App\Repository\GlobalSettingValueRepository;
use App\Repository\TeacherSettingValueRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a SettingFile if it's no longer referenced by any setting value row
 * (global, centre, or teacher). Must be called after flushing the change that
 * stopped pointing to the file (replacement or deletion of the reference), so
 * that the count reflects the already-updated state.
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
