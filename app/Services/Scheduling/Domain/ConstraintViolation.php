<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use InvalidArgumentException;

final readonly class ConstraintViolation implements SchedulingContract
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $ruleId,
        public string $message,
        public string $severity = 'hard',
        public string $scope = 'schedule_row',
        public array $context = [],
    ) {
        if ($this->ruleId === '') {
            throw new InvalidArgumentException('Constraint rule ID cannot be empty.');
        }

        if ($this->message === '') {
            throw new InvalidArgumentException('Constraint violation message cannot be empty.');
        }

        if (! in_array($this->severity, ['hard', 'warning', 'information', 'soft'], true)) {
            throw new InvalidArgumentException('Unsupported constraint violation severity.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $ruleId = (string) ($payload['rule_id'] ?? $payload['rule'] ?? $payload['code'] ?? '');
        $message = (string) ($payload['message'] ?? '');
        $reserved = ['rule_id', 'rule', 'code', 'message', 'severity', 'scope', 'context'];
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        foreach ($payload as $key => $value) {
            if (! in_array($key, $reserved, true)) {
                $context[$key] = $value;
            }
        }

        return new self(
            ruleId: $ruleId,
            message: $message,
            severity: (string) ($payload['severity'] ?? 'hard'),
            scope: (string) ($payload['scope'] ?? 'schedule_row'),
            context: $context,
        );
    }

    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'message' => $this->message,
            'severity' => $this->severity,
            'scope' => $this->scope,
            'context' => $this->context,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
