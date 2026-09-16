<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Onboarding;

use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardStore;

/**
 * In-memory WizardStore so the service is exercised against a real (if
 * trivial) store without a WordPress option table.
 */
final class FakeWizardStore implements WizardStore
{
    public bool $saved = false;

    public function __construct(public ?Wizard $stored = null)
    {
    }

    public function load(): Wizard
    {
        return $this->stored ?? Wizard::fresh();
    }

    public function save(Wizard $wizard): void
    {
        $this->stored = $wizard;
        $this->saved = true;
    }
}
