<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardStore;

/**
 * Global, non-autoloaded WordPress option persistence for the wizard aggregate.
 *
 * The whole onboarding decision lives in the single option `cast_onboarding`
 * (autoload disabled) — never in per-user user-meta. WordPress option access is
 * kept strictly separate from the Finite transition graph: this store only
 * (de)serializes the aggregate to/from the option.
 */
final class WordPressWizardStore implements WizardStore
{
    public const OPTION_KEY = 'cast_onboarding';

    public function load(): Wizard
    {
        return Wizard::fromArray(get_option(self::OPTION_KEY, null));
    }

    public function save(Wizard $wizard): void
    {
        update_option(self::OPTION_KEY, $wizard->toArray(), false);
    }
}
