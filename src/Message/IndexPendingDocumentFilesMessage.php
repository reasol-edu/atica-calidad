<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Every few minutes (see Schedule.php): reads the next batch of stored files whose text is not in the search yet — everything
 * uploaded before the index existed, what a worker missed, and what an newer extractor reads better. Needs no manual step
 * after an update.
 */
final class IndexPendingDocumentFilesMessage {}
