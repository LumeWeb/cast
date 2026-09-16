<?php
/**
 * Getting Started admin page — the guided onboarding wizard shell.
 *
 * Presentation / iteration / escaping only. Every business decision (which
 * screen to show, which controls are available, the progress position, the
 * install allowlist rows, the protected action names) is resolved by
 * OnboardingAdminSubscriber via WizardView and handed in as explicit view
 * data; no raw aggregate state is ever read here.
 *
 * No output buffering or string-built HTML: this template emits static markup,
 * iterates the provided arrays, and escapes every dynamic value. The dedicated
 * layout is styled by assets/css/cast-admin.css (enqueued only on this screen).
 *
 * Available view keys:
 *   - screen: string                              welcome|choose|install|complete|skipped
 *   - menuTitle: string
 *   - progressSteps: list<array{label: string, current: bool}>
 *   - adminPostUrl, nonceField: string
 *   - startAction, skipAction, completeAction, selectBuilderAction,
 *     resetBuilderAction, reopenAction: string
 *   - choices: list<array{value,label,isRecommended,isCore,costNote,lockInNote,performanceNote,...}>
 *   - selectedBuilderLabel: ?string
 *   - readyToComplete, canSkip, canReopen, canFinish: bool
 *   - resultMessage: ?string
 *   - installRows: list<array{slug,label,name,basename,alreadyInstalled,alreadyActive,buttonLabel,buttonDisabled}>
 *   - editorUrl, dashboardUrl: string
 *
 * @var array<string, mixed> $view
 */
$screen = (string) ($view['screen'] ?? 'welcome');
$progressSteps = $view['progressSteps'] ?? [];
$choices = $view['choices'] ?? [];
$installRows = $view['installRows'] ?? [];
$selectedBuilderLabel = (string) ($view['selectedBuilderLabel'] ?? '');

// The progress tracker only lights a step once the wizard is underway; the
// welcome screen (currentStep 0) hides the numbered tracker entirely.
$showProgress = in_array(true, array_column($progressSteps, 'current'), true);
?>
<div class="wrap cast-wizard-wrap">

    <header class="cast-wizard-header">
        <span class="dashicons dashicons-admin-generic cast-wizard-mark" aria-hidden="true"></span>
        <div class="cast-wizard-heading">
            <h1><?php echo esc_html($view['menuTitle'] ?? ''); ?></h1>
            <p class="cast-wizard-subtitle"><?php echo esc_html('Choose how you want to build, then we\'ll help you get started.'); ?></p>
        </div>
    </header>

    <?php if ($showProgress) : ?>
        <nav class="cast-progress-wrap" aria-label="Onboarding progress">
            <ol class="cast-progress cast-progress-horizontal">
                <?php foreach ($progressSteps as $stepIndex => $step) : ?>
                    <li class="cast-progress-step<?php if ($step['current']) : ?> cast-progress-step-current<?php endif; ?>"<?php if ($step['current']) : ?> aria-current="step"<?php endif; ?>>
                        <span class="cast-progress-number" aria-hidden="true"><?php echo (int) $stepIndex + 1; ?></span>
                        <span class="cast-progress-label"><?php echo esc_html($step['label']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <?php if ($screen === 'welcome') : ?>
        <div class="cast-screen-frame">
            <?php include __DIR__ . '/welcome.php'; ?>
        </div>

    <?php elseif ($screen === 'choose') : ?>
        <div class="cast-screen-frame">
            <section class="card cast-choose-card" aria-labelledby="cast-choose-heading">
                <h2 id="cast-choose-heading"><?php echo esc_html('Choose how you build'); ?></h2>
                <p><?php echo esc_html('One pick sets your default editing experience. You can change it here at any time.'); ?></p>

                <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                    <?php echo $view['nonceField']; ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr($view['selectBuilderAction']); ?>">

                    <fieldset class="cast-choice-fieldset">
                        <legend><?php echo esc_html('Page builder'); ?></legend>

                        <?php foreach ($choices as $choice) : ?>
                            <label class="cast-choice-card<?php if ($choice['isRecommended']) : ?> cast-choice-card-recommended<?php endif; ?>">
                                <input
                                    type="radio"
                                    name="builder"
                                    value="<?php echo esc_attr($choice['value']); ?>"
                                    <?php if ($choice['isRecommended']) : ?>checked="checked"<?php endif; ?>
                                >
                                <span class="cast-choice-title"><?php echo esc_html($choice['label']); ?></span>
                                <?php if ($choice['isCore']) : ?>
                                    <span class="cast-choice-badge"><?php echo esc_html('Part of WordPress core'); ?></span>
                                <?php endif; ?>
                                <span class="cast-choice-note"><?php echo esc_html($choice['costNote']); ?></span>
                                <span class="cast-choice-note"><?php echo esc_html($choice['lockInNote']); ?></span>
                                <span class="cast-choice-note"><?php echo esc_html($choice['performanceNote']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <div class="cast-actions">
                        <button type="submit" class="button button-primary cast-action-full"><?php echo esc_html('Continue'); ?></button>
                    </div>
                </form>

                <?php if ($view['canSkip']) : ?>
                    <div class="cast-secondary-actions">
                        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                            <?php echo $view['nonceField']; ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($view['skipAction']); ?>">
                            <button type="submit" class="button"><?php echo esc_html('Skip for now'); ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>

    <?php elseif ($screen === 'install') : ?>
        <div class="cast-screen-frame">
            <section class="card cast-install-card" aria-labelledby="cast-install-heading">
                <h2 id="cast-install-heading"><?php echo esc_html('Install your page builder'); ?></h2>

                <?php if ($selectedBuilderLabel !== '') : ?>
                    <div class="cast-build-summary">
                        <span class="cast-build-summary-label"><?php echo esc_html('Selected builder'); ?></span>
                        <strong class="cast-build-summary-value"><?php echo esc_html($selectedBuilderLabel); ?></strong>
                    </div>
                <?php endif; ?>

                <?php if ($view['resultMessage'] !== null) : ?>
                    <div class="notice notice-error cast-error-panel">
                        <p><?php echo esc_html($view['resultMessage']); ?></p>
                    </div>
                <?php endif; ?>

                <div class="cast-install-body">
                    <?php if ($installRows !== []) : ?>
                        <ul class="cast-install-list">
                            <?php foreach ($installRows as $row) : ?>
                                <li class="cast-install-item">
                                    <span class="cast-install-item-meta">
                                        <strong><?php echo esc_html($row['label']); ?></strong>
                                        <?php if ($row['basename'] !== null) : ?>
                                            <code><?php echo esc_html($row['basename']); ?></code>
                                        <?php endif; ?>
                                    </span>
                                    <button
                                        type="button"
                                        class="button button-primary cast-install-button cast-action-full"
                                        data-cast-slug="<?php echo esc_attr($row['slug']); ?>"
                                        <?php if ($row['buttonDisabled']) : ?>disabled="disabled"<?php endif; ?>
                                    ><?php echo esc_html($row['buttonLabel']); ?></button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p id="cast-install-live" class="cast-install-live" aria-live="polite" role="status"></p>
                    <?php else : ?>
                        <p><?php echo esc_html('Nothing to install — ' . $selectedBuilderLabel . ' is ready to go.'); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($view['readyToComplete']) : ?>
                    <div class="cast-actions">
                        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                            <?php echo $view['nonceField']; ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($view['completeAction']); ?>">
                            <button type="submit" class="button button-primary cast-action-full"><?php echo esc_html('Complete setup'); ?></button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="cast-secondary-actions">
                    <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                        <?php echo $view['nonceField']; ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr($view['resetBuilderAction']); ?>">
                        <button type="submit" class="button"><?php echo esc_html('Choose a different builder'); ?></button>
                    </form>

                    <?php if ($view['canSkip']) : ?>
                        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                            <?php echo $view['nonceField']; ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($view['skipAction']); ?>">
                            <button type="submit" class="button"><?php echo esc_html('Skip for now'); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>

    <?php elseif ($screen === 'complete') : ?>
        <div class="cast-screen-frame">
            <section class="card cast-complete-card" aria-labelledby="cast-complete-heading">
                <h2 id="cast-complete-heading"><?php echo esc_html('Setup complete'); ?></h2>
                <?php if ($selectedBuilderLabel !== '') : ?>
                    <p><?php echo esc_html('Ready to build with ' . $selectedBuilderLabel . '.'); ?></p>
                <?php endif; ?>

                <div class="cast-actions">
                    <a class="button button-primary cast-action-full" href="<?php echo esc_url($view['editorUrl']); ?>"><?php echo esc_html('Create your first page'); ?></a>

                    <?php if ($view['canFinish']) : ?>
                        <!-- Finish setup confirms the flow. It is deliberately a
                             plain secondary WordPress button (never a second
                             full-width primary CTA): the grey control below the
                             blue primary receives its own nonce-guarded form and
                             is separated by the shared action-group gap. -->
                        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                            <?php echo $view['nonceField']; ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($view['completeAction']); ?>">
                            <button type="submit" class="button"><?php echo esc_html('Finish setup'); ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="cast-secondary-actions">
                    <a class="button-link" href="<?php echo esc_url($view['dashboardUrl']); ?>"><?php echo esc_html('Return to dashboard'); ?></a>

                    <?php if ($view['canFinish']) : ?>
                        <!-- Still Building: choosing a different builder is the
                             valid way to change the selection; reopen is only
                             legal once the flow is settled (skipped/completed). -->
                        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                            <?php echo $view['nonceField']; ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($view['resetBuilderAction']); ?>">
                            <button type="submit" class="button"><?php echo esc_html('Choose a different builder'); ?></button>
                        </form>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                            <?php echo $view['nonceField']; ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($view['reopenAction']); ?>">
                            <button type="submit" class="button"><?php echo esc_html('Reopen setup'); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>

    <?php elseif ($screen === 'skipped') : ?>
        <div class="cast-screen-frame">
            <section class="card cast-skipped-card" aria-labelledby="cast-skipped-heading">
                <h2 id="cast-skipped-heading"><?php echo esc_html('Setup skipped'); ?></h2>
                <p><?php echo esc_html('You can run the setup whenever you are ready.'); ?></p>

                <div class="cast-actions">
                    <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                        <?php echo $view['nonceField']; ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr($view['reopenAction']); ?>">
                        <button type="submit" class="button button-primary cast-action-full"><?php echo esc_html('Reopen setup'); ?></button>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
