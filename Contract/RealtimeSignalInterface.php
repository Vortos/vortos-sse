<?php

declare(strict_types=1);

namespace Vortos\Sse\Contract;

/**
 * A monotonically increasing per-channel version used to tell a connected client
 * that "something changed on this channel, refetch". It is a lightweight nudge,
 * never a payload transport, so it stays multi-tab safe and cannot drift from
 * the authoritative data.
 *
 * Implementations MUST be fail-safe: signalling is an optimisation over a
 * client's polling backstop, never a correctness dependency. A backend that is
 * unavailable must degrade to no-op / version 0, not throw.
 */
interface RealtimeSignalInterface
{
    /** Bump the channel's version. Best-effort. */
    public function signal(string $channel): void;

    /** Current version of the channel, or 0 if unknown/unavailable. */
    public function version(string $channel): int;
}
