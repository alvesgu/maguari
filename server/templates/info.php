<?php declare(strict_types=1);
/**
 * A visible (i) icon whose details float next to it (design section 10.3).
 * The details are an HTML popover: the browser shows them on top of the page
 * and closes them on a click or tap outside and on Escape. The button opens
 * them on click and tap even without a script; assets/maguari.js adds hover
 * and keyboard focus and places them next to the icon. aria-describedby gives
 * screen readers the text. The icon's "i" is CSS-generated, so selecting and
 * copying a result never includes it. Line breaks in $text are kept.
 *
 * @var \Closure $e @var string $text
 */
$id = 'info-' . bin2hex(random_bytes(6));
?><span class="info"><button type="button" class="info-icon" aria-label="Details" aria-describedby="<?= $id ?>" popovertarget="<?= $id ?>"></button><span class="info-text" id="<?= $id ?>" popover role="tooltip"><?= $e($text) ?></span></span><?php
