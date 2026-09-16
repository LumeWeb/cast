<?php
/**
 * Accessible read-only list of curated page-builder candidates.
 *
 * Presentation/iteration/escaping only. The candidate labels and their status
 * notes are pre-computed by the business layer (PageBuilderCatalog) and handed
 * in as explicit view data; nothing is installed or mutated here.
 *
 * @var array<string, mixed> $view
 * @var list<array{label: string, meta: string}> $rows
 */
$rows = $view['recommendations'];
?>
<section aria-labelledby="cast-page-builders-heading">
    <h2 id="cast-page-builders-heading"><?php echo esc_html('Page builder recommendations'); ?></h2>
    <p><?php echo esc_html('A curated starting point; nothing is installed automatically.'); ?></p>
    <ul>
        <?php foreach ($rows as $row) : ?>
            <li>
                <strong><?php echo esc_html($row['label']); ?></strong>
                <?php if ($row['meta'] !== '') : ?>
                    — <?php echo esc_html($row['meta']); ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
