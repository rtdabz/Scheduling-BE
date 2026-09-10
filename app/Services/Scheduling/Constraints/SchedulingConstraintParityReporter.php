<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Constraints;

use App\Services\Scheduling\Domain\ConstraintViolation;

final class SchedulingConstraintParityReporter
{
    /**
     * @param  iterable<array<string, mixed>|ConstraintViolation>  $legacyViolations
     * @param  iterable<array<string, mixed>|ConstraintViolation>  $kernelViolations
     * @param  list<string>  $ruleIds
     * @return array{matches: bool, legacy_rule_ids: list<string>, kernel_rule_ids: list<string>, legacy_only: list<string>, kernel_only: list<string>}
     */
    public function compare(iterable $legacyViolations, iterable $kernelViolations, array $ruleIds = []): array
    {
        $legacy = $this->ruleIds($legacyViolations, $ruleIds);
        $kernel = $this->ruleIds($kernelViolations, $ruleIds);

        return [
            'matches' => $legacy === $kernel,
            'legacy_rule_ids' => $legacy,
            'kernel_rule_ids' => $kernel,
            'legacy_only' => array_values(array_diff($legacy, $kernel)),
            'kernel_only' => array_values(array_diff($kernel, $legacy)),
        ];
    }

    /**
     * @param  iterable<array<string, mixed>|ConstraintViolation>  $violations
     * @param  list<string>  $scope
     * @return list<string>
     */
    private function ruleIds(iterable $violations, array $scope): array
    {
        $ids = [];
        foreach ($violations as $violation) {
            $ruleId = $violation instanceof ConstraintViolation
                ? $violation->ruleId
                : (string) ($violation['rule_id'] ?? $violation['rule'] ?? $violation['code'] ?? '');

            if ($ruleId !== '' && ($scope === [] || in_array($ruleId, $scope, true))) {
                $ids[$ruleId] = $ruleId;
            }
        }

        sort($ids);

        return array_values($ids);
    }
}
