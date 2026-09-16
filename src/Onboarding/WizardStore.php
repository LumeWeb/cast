<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Onboarding;

/**
 * Persistence seam for the wizard aggregate, isolating WordPress option access
 * so the transition service stays testable without a database.
 */
interface WizardStore
{
    public function load(): Wizard;

    public function save(Wizard $wizard): void;
}
