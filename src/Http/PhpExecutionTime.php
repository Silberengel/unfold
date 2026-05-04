<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Web request caps for max_execution_time. High values tie up Apache event MPM scoreboard slots
 * (and similar) for minutes per client; keep limits aligned with real Nostr + worker wall time.
 */
final class PhpExecutionTime
{
    /** Article page, comments fragment, author profile (Nostr + parallel workers + Twig). */
    public const NOSTR_BOUND_WEB_SEC = 120;

    /** DB + Twig listing routes without relay fan-out. */
    public const LIGHT_WEB_SEC = 60;
}
