<?php

function renderSetupWizardStyles(): void
{
    ?>
    <style>
        .wizard-shell { max-width: 960px; margin: 0 auto; }
        .wizard-steps {
            display: flex;
            gap: 0.35rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .wizard-step {
            flex: 1 1 120px;
            text-align: center;
            padding: 0.65rem 0.5rem;
            border-radius: 0.65rem;
            border: 1px solid var(--admin-border, #d7def2);
            background: #fff;
            color: var(--admin-muted, #5f6791);
            font-size: 0.78rem;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .wizard-step .step-num {
            display: inline-flex;
            width: 1.4rem;
            height: 1.4rem;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #e9eeff;
            font-weight: 700;
            margin-right: 0.25rem;
            font-size: 0.72rem;
        }
        .wizard-step.done {
            border-color: #9fd5b0;
            color: #146c43;
            background: #f3fff7;
        }
        .wizard-step.done .step-num { background: #198754; color: #fff; }
        .wizard-step.active {
            border-color: #1f2f88;
            background: linear-gradient(145deg, #fff, #f0f3ff);
            color: #1f2f88;
            font-weight: 600;
            box-shadow: 0 4px 14px rgba(31, 47, 136, 0.12);
        }
        .wizard-step.active .step-num { background: #1f2f88; color: #fff; }
        .wizard-step.disabled {
            pointer-events: none;
            opacity: 0.55;
        }
        .wizard-card {
            border: 1px solid var(--admin-border, #d7def2);
            border-radius: 0.95rem;
            padding: 1.5rem;
            background: #fff;
            box-shadow: 0 8px 20px rgba(31, 47, 136, 0.06);
        }
        .wizard-card h3 { font-size: 1.15rem; margin-bottom: 0.35rem; }
        .wizard-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid #e9eeff;
            flex-wrap: wrap;
        }
        .scan-row {
            border: 1px solid #e9eeff;
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 0.75rem;
            background: #fafbff;
        }
        .scan-row label { font-weight: 600; color: #1f2f88; }
    </style>
    <?php
}

function renderSetupWizardStepper(
    int $currentStep,
    int $eventId,
    array $readiness,
    bool $allowNavigation = true
): void {
    $steps = getSetupWizardSteps();
    $checks = $readiness['checks'] ?? [];
    $checkKeys = ['details', 'geofence', 'scan_rules', 'penalty_rules', 'review'];
    ?>
    <div class="wizard-steps">
        <?php foreach ($steps as $num => $meta): ?>
            <?php
            $classes = ['wizard-step'];
            $checkKey = $checkKeys[$num - 1] ?? null;

            if ($num === 5) {
                $done = !empty($readiness['ok']);
            } else {
                $done = $checkKey && !empty($checks[$checkKey]['ok']);
            }

            if ($num === $currentStep) {
                $classes[] = 'active';
            } elseif ($done) {
                $classes[] = 'done';
            }

            $href = $eventId > 0
                ? "event_setup.php?event_id={$eventId}&step={$num}"
                : '#';

            if (!$allowNavigation || $eventId <= 0) {
                $classes[] = 'disabled';
                $href = '#';
            }
            ?>
            <a href="<?= htmlspecialchars($href) ?>" class="<?= implode(' ', $classes) ?>">
                <span class="step-num"><?= $done && $num !== $currentStep ? '✓' : $num ?></span>
                <?= htmlspecialchars($meta['short']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderWizardNav(
    int $currentStep,
    int $eventId,
    ?string $backHref = null,
    ?string $nextHref = null,
    bool $showNext = true,
    string $nextLabel = 'Save & Continue',
    bool $nextIsSubmit = true
): void {
    ?>
    <div class="wizard-nav">
        <div>
            <?php if ($backHref): ?>
                <a href="<?= htmlspecialchars($backHref) ?>" class="btn btn-outline-secondary">← Back</a>
            <?php else: ?>
                <a href="events.php" class="btn btn-outline-secondary">← Events List</a>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <?php if ($showNext): ?>
                <?php if ($nextIsSubmit): ?>
                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars($nextLabel) ?> →</button>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($nextHref) ?>" class="btn btn-primary"><?= htmlspecialchars($nextLabel) ?> →</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
