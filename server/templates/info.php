<?php declare(strict_types=1);
/**
 * A visible (i) icon whose text shows on mouse hover and on keyboard focus
 * (design section 10.3). The icon is a button, so Tab reaches it, and
 * aria-describedby gives screen readers the text. Shown by CSS alone: the
 * Content-Security-Policy allows no inline scripts. Line breaks in $text are
 * kept.
 *
 * @var \Closure $e @var string $text
 */
$id = 'info-' . bin2hex(random_bytes(6));
?><span class="info"><button type="button" class="info-icon" aria-label="Details" aria-describedby="<?= $id ?>">i</button><span class="info-text" id="<?= $id ?>" role="tooltip"><?= $e($text) ?></span></span><?php
