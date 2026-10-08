/*
 * Maguari's only script: the details behind each (i) icon
 * (templates/info.php, design section 10.3). External, because the
 * Content-Security-Policy blocks inline scripts.
 *
 * The details are HTML popovers. The browser shows them on top of the page,
 * opens and closes them when the icon is clicked or tapped (popovertarget,
 * which works without this script) and closes them on a click or tap outside
 * and on Escape. This script adds the rest:
 *
 * - places them next to the icon, below it or above when there is no room;
 * - opens them on mouse hover and closes them shortly after the mouse leaves
 *   both the icon and the details, so the mouse can move into them;
 * - opens them on keyboard focus and closes them when focus moves on;
 * - keeps them open once clicked, tapped or pressed into, for example to
 *   select and copy their text, until a click outside or Escape.
 */
(function () {
    'use strict';

    var GAP = 6;
    var EDGE = 8;
    var HIDE_DELAY_MS = 250;

    // Details opened by hover or focus, not yet kept open, by popover.
    var transient = new Set();
    var hideTimers = new Map();

    function iconOf(details) {
        return document.querySelector('.info-icon[popovertarget="' + details.id + '"]');
    }

    function detailsOf(icon) {
        return document.getElementById(icon.getAttribute('popovertarget'));
    }

    function isOpen(details) {
        return details.matches(':popover-open');
    }

    function place(details) {
        var icon = iconOf(details);

        if (!icon) {
            return;
        }

        var anchor = icon.getBoundingClientRect();
        var width = details.offsetWidth;
        var height = details.offsetHeight;
        var left = Math.min(anchor.left, window.innerWidth - width - EDGE);
        var top = anchor.bottom + GAP;

        if (top + height > window.innerHeight - EDGE && anchor.top - GAP - height >= EDGE) {
            top = anchor.top - GAP - height;
        }

        // CSSOM properties, which the Content-Security-Policy allows.
        details.style.left = Math.max(EDGE, left) + 'px';
        details.style.top = Math.max(EDGE, top) + 'px';
    }

    function cancelHide(details) {
        clearTimeout(hideTimers.get(details));
        hideTimers.delete(details);
    }

    function show(details, keep) {
        cancelHide(details);

        if (keep) {
            transient.delete(details);
        } else if (!isOpen(details)) {
            transient.add(details);
        }

        if (!isOpen(details)) {
            details.showPopover();
        }
    }

    function hideSoon(details) {
        if (!transient.has(details)) {
            return;
        }

        cancelHide(details);
        hideTimers.set(details, setTimeout(function () {
            hideTimers.delete(details);

            if (transient.has(details) && isOpen(details)) {
                details.hidePopover();
            }
        }, HIDE_DELAY_MS));
    }

    function closestDetails(target) {
        if (!(target instanceof Element)) {
            return null;
        }

        var icon = target.closest('.info-icon');

        return icon ? detailsOf(icon) : target.closest('.info-text');
    }

    // Hover, for a mouse only: a tap is handled as a click.
    document.addEventListener('pointerover', function (event) {
        var details = event.pointerType === 'mouse' ? closestDetails(event.target) : null;

        if (details) {
            show(details, false);
        }
    });

    document.addEventListener('pointerout', function (event) {
        var details = event.pointerType === 'mouse' ? closestDetails(event.target) : null;

        if (details && closestDetails(event.relatedTarget) !== details) {
            hideSoon(details);
        }
    });

    // Pressing into the details (to select text) keeps them open.
    document.addEventListener('pointerdown', function (event) {
        var details = event.target instanceof Element ? event.target.closest('.info-text') : null;

        if (details) {
            show(details, true);
        }
    });

    // A click, tap or Enter on the icon keeps its details open; on details
    // already kept open it closes them. Without this script, popovertarget
    // toggles them.
    document.addEventListener('click', function (event) {
        var icon = event.target instanceof Element ? event.target.closest('.info-icon') : null;
        var details = icon ? detailsOf(icon) : null;

        if (!details) {
            return;
        }

        event.preventDefault();

        if (isOpen(details) && !transient.has(details)) {
            details.hidePopover();
        } else {
            show(details, true);
        }
    });

    // Keyboard focus opens the details; moving on closes them unless kept open.
    document.addEventListener('focusin', function (event) {
        var icon = event.target instanceof Element ? event.target.closest('.info-icon') : null;

        if (icon && icon.matches(':focus-visible')) {
            show(detailsOf(icon), false);
        }
    });

    document.addEventListener('focusout', function (event) {
        var icon = event.target instanceof Element ? event.target.closest('.info-icon') : null;
        var details = icon ? detailsOf(icon) : null;

        if (details && transient.has(details) && isOpen(details)) {
            details.hidePopover();
        }
    });

    // Place the details whenever they open, and keep them next to the icon
    // while the page or a table scrolls, or the window changes size.
    document.addEventListener('toggle', function (event) {
        var details = event.target;

        if (!(details instanceof Element) || !details.matches('.info-text')) {
            return;
        }

        if (event.newState === 'open') {
            place(details);
        } else {
            transient.delete(details);
            cancelHide(details);
        }
    }, true);

    function placeOpen() {
        document.querySelectorAll('.info-text:popover-open').forEach(place);
    }

    window.addEventListener('scroll', placeOpen, true);
    window.addEventListener('resize', placeOpen);
}());
