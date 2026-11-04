<?php

namespace App\Services\Archive;

use RuntimeException;

/** A known precondition stops a restore; nothing was changed. */
final class ArchiveRestoreBlocked extends RuntimeException
{
    /** @param list<array<string, mixed>> $blockers */
    public function __construct(private readonly array $blockers)
    {
        parent::__construct((string) ($blockers[0]['message'] ?? 'This record cannot be restored yet.'));
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'message' => count($this->blockers) === 1
                ? $this->getMessage()
                : 'This record cannot be restored until '.count($this->blockers).' issues are resolved.',
            'code' => 'restore_blocked',
            'blockers' => $this->blockers,
        ];
    }
}
