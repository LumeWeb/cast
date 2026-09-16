<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Onboarding;

/**
 * The wizard aggregate: the single global onboarding decision record.
 *
 * The aggregate owns exactly the fields the flow needs now — schema version,
 * current state, current step, selected builder, builder install status, the
 * last structured result code, and an updated timestamp. It is a plain data
 * holder with explicit, validated (de)serialization; all state changes are
 * driven by Finite through WizardService, never by direct field writes from
 * request input.
 *
 * The {@see $state} property is deliberately non-readonly and typed to the
 * Finite State enum so the machine can re-assign it during apply().
 */
final class Wizard
{
    public const SCHEMA_VERSION = 1;

    public WizardState $state;

    public function __construct(
        WizardState $state = WizardState::NotStarted,
        public int $schemaVersion = self::SCHEMA_VERSION,
        public ?string $step = null,
        public ?string $selectedBuilder = null,
        public InstallStatus $installStatus = InstallStatus::Idle,
        public ?ResultCode $resultCode = null,
        public int $updatedAt = 0,
    ) {
        $this->state = $state;
    }

    public static function fresh(): self
    {
        return new self();
    }

    /**
     * Rebuild an aggregate from a persisted option value.
     *
     * Missing data, a non-array, or a schema version we do not recognise are
     * treated as a fresh, never-started wizard. A mismatched/missing schema
     * therefore never silently migrates or guesses old formats (hard cutover:
     * no legacy reads, no compatibility aliases).
     *
     * @param mixed $data The raw option value.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return self::fresh();
        }

        $step = $data['step'] ?? null;
        $builder = $data['selected_builder'] ?? null;
        $updatedAt = $data['updated_at'] ?? 0;

        return new self(
            state: WizardState::fromStored($data['state'] ?? null),
            step: is_string($step) ? $step : null,
            selectedBuilder: is_string($builder) ? $builder : null,
            installStatus: is_string($data['install_status'] ?? null)
                ? (InstallStatus::tryFrom($data['install_status']) ?? InstallStatus::Idle)
                : InstallStatus::Idle,
            resultCode: is_string($data['result_code'] ?? null)
                ? ResultCode::tryFrom($data['result_code'])
                : null,
            updatedAt: is_int($updatedAt) ? $updatedAt : 0,
        );
    }

    /**
     * Explicit, validated serialization for the option value.
     *
     * @return array{
     *     schema_version: int,
     *     state: string,
     *     step: ?string,
     *     selected_builder: ?string,
     *     install_status: string,
     *     result_code: ?string,
     *     updated_at: int
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'state' => $this->state->value,
            'step' => $this->step,
            'selected_builder' => $this->selectedBuilder,
            'install_status' => $this->installStatus->value,
            'result_code' => $this->resultCode?->value,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function touch(?int $at = null): void
    {
        $this->updatedAt = $at ?? time();
    }
}
