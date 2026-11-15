<?php

declare(strict_types=1);

namespace App\Message;

/** Every few minutes (see Schedule.php): proves that a worker is running and consuming the scheduler. Shown in Administración → Estado del sistema. */
final class HeartbeatMessage {}
