<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Exceptions\GenerationConfigurationConfirmationException;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfigurationConfirmation;
use App\Services\Scheduling\Domain\PreparedGenerationConfiguration;
use InvalidArgumentException;

final class PrepareGenerationConfigurationForSolve
{
    public function __construct(
        private readonly LegacyGenerationConfigurationMapper $mapper,
        private readonly ValidateGenerationConfiguration $validator,
        private readonly GenerationConfigurationFingerprint $fingerprint,
    ) {}

    /** @param array<string, mixed> $input */
    public function prepareLegacy(int $termId, int $departmentId, array $input): PreparedGenerationConfiguration
    {
        $configuration = $this->mapper->map($input);
        $validation = $this->validator->validate($termId, $departmentId, $configuration);
        $configurationFingerprint = $this->fingerprint->calculate($configuration);
        $confirmation = $this->confirmation($input);
        $warningRuleIds = array_values(array_unique(array_map(
            static fn (ConstraintViolation $violation): string => $violation->ruleId,
            array_values(array_filter(
                $validation->violations,
                static fn (ConstraintViolation $violation): bool => $violation->severity === 'warning',
            )),
        )));

        if (! $validation->canGenerate()) {
            throw new GenerationConfigurationConfirmationException(
                $validation,
                $configurationFingerprint,
                [],
                'generation_configuration_invalid',
                'The generation configuration contains blocking scheduling constraints.',
            );
        }

        if ($confirmation !== null && ! hash_equals(
            $configurationFingerprint,
            $confirmation->configurationFingerprint,
        )) {
            throw new GenerationConfigurationConfirmationException(
                $validation,
                $configurationFingerprint,
                $warningRuleIds,
                'configuration_confirmation_stale',
                'The generation configuration changed after it was confirmed. Review and confirm the current configuration.',
            );
        }

        $confirmedRuleIds = $confirmation?->confirmedWarningRuleIds ?? [];
        $missingRuleIds = array_values(array_diff($warningRuleIds, $confirmedRuleIds));
        if ($missingRuleIds !== []) {
            throw new GenerationConfigurationConfirmationException(
                $validation,
                $configurationFingerprint,
                $warningRuleIds,
                'configuration_confirmation_required',
                'Review and confirm the configuration warnings before generating a schedule.',
            );
        }

        return new PreparedGenerationConfiguration(
            configuration: $configuration,
            validation: $validation,
            configurationFingerprint: $configurationFingerprint,
            confirmedWarningRuleIds: array_values(array_intersect($warningRuleIds, $confirmedRuleIds)),
        );
    }

    /** @param array<string, mixed> $input */
    private function confirmation(array $input): ?GenerationConfigurationConfirmation
    {
        $payload = $input['configuration_confirmation'] ?? null;
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Configuration confirmation must be an object.');
        }

        return GenerationConfigurationConfirmation::fromArray($payload);
    }
}
