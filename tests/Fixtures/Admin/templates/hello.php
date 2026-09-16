<?php
/**
 * Fixture: minimal template proving the renderer includes a PHP template and
 * binds explicit view data into $view. The raw open tag confirms the template
 * (not the renderer) owns rendering/escaping of the view data.
 *
 * @var array<string, mixed> $view
 */
?>
<p>Hello World, <strong><?php echo esc_html($view['name']); ?></strong></p>
