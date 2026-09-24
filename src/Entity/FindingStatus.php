<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where a Finding stands in its life cycle — the marking of the "finding" state machine
 * (config/packages/workflow.yaml), which decides which step comes next and who may take it:
 *
 *   reported ─┬─ classify_nonconformity ─→ analysis ─ submit_analysis ─→ execution
 *             ├─ classify_other ─────────────────────────────────────→ execution
 *             └─ discard ─→ discarded
 *   execution ─ request_verification ─→ verification ─┬─ verify_effective ─→ closed
 *                                                     └─ verify_ineffective ─→ analysis
 *   execution ─ close (observations and improvement opportunities) ─→ closed
 */
enum FindingStatus: string
{
    /** Just reported, waiting for the quality manager to classify it. */
    case Reported = 'reported';
    /** A nonconformity whose causes have to be analysed and its corrective actions defined. */
    case Analysis = 'analysis';
    /** Its actions are being carried out. */
    case Execution = 'execution';
    /** Every action done: the quality manager has to check whether it worked. */
    case Verification = 'verification';
    case Closed = 'closed';
    /** Not a finding after all (classified away, with a reason). */
    case Discarded = 'discarded';

    public function isOpen(): bool
    {
        return !\in_array($this, [self::Closed, self::Discarded], true);
    }
}
