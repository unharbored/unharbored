<?php

declare(strict_types=1);

namespace App\Lib\Audit;

/**
 * Whether an audited action succeeded.
 *
 * Failures and denials are recorded, not just successes. An audit log that
 * only contains things that worked cannot answer "what did they try", which
 * is usually the more interesting question after an incident.
 */
enum Outcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Denied = 'denied';
}
