<?php
/**
 * Shared pages: one drop-down card ("section") used everywhere, so every page looks and behaves the same.
 * Important sections start open, the rest start closed. The person can open or close any of them and the choice is
 * remembered on this device (browser storage, same key on every group). A closed section still shows a one-line summary.
 *
 *   shared_sec('settle', 'Settle up', 'send', 'orange', true, 'You pay 2 people', '2');
 *   ... normal HTML for the body ...
 *   shared_sec_end();
 *   // once per page, in the scripts of ef_page_end:  shared_sec_js()
 */

/** Colour names a section may use. Each has an accent colour (see assets/app.css, .sec-<name>). */
const SHARED_SEC_COLORS = ['blue', 'orange'];

function shared_sec(string $key, string $title, string $icon, string $color, bool $open, string $meta = '', string $badge = ''): void
{
    $color = in_array($color, SHARED_SEC_COLORS, true) ? $color : 'blue';
    echo '<details class="g card sec sec-' . $color . '" data-sec="' . e($key) . '"' . ($open ? ' open' : '') . '>';
    echo '<summary><span class="sec-ic">' . ef_icon($icon) . '</span>';
    echo '<span class="sec-tt"><b>' . e(t($title)) . '</b>' . ($meta !== '' ? '<small>' . e(t($meta)) . '</small>' : '') . '</span>';
    echo ($badge !== '' ? '<span class="sec-bd">' . e($badge) . '</span>' : '<span></span>');
    echo '<span class="sec-ch" aria-hidden="true"></span></summary><div class="sec-body">';
}

/** Always-visible card with the same header look as a section but no drop-down: for the main content of a page. Plain pages use blue; only "Settle up" is orange. */
function shared_panel(string $title, string $icon, string $meta = '', string $badge = '', string $tone = 'blue'): void
{
    $tone = $tone === 'orange' ? 'orange' : 'blue';
    echo '<section class="g card sec-panel sec-' . $tone . '"><div class="sec-head"><span class="sec-ic">' . ef_icon($icon) . '</span>';
    echo '<span class="sec-tt"><b>' . e(t($title)) . '</b>' . ($meta !== '' ? '<small class="sec-meta">' . e(t($meta)) . '</small>' : '') . '</span>';
    echo '</div><div class="sec-body">'; // $badge is accepted so panel and section calls look alike; panels show the summary line instead
}

function shared_panel_end(): void
{
    echo '</div></section>';
}

function shared_sec_end(): void
{
    echo '</div></details>';
}

/** Remembers which sections the person opened or closed (per device). Safe when storage is blocked. */
function shared_sec_js(): string
{
    return '<script>(function(){var K="ef-sec-";document.querySelectorAll("details.sec[data-sec]").forEach(function(d){var k=K+d.getAttribute("data-sec");try{var v=localStorage.getItem(k);if(v==="1"){d.open=true}else if(v==="0"){d.open=false}}catch(e){}d.addEventListener("toggle",function(){try{localStorage.setItem(k,d.open?"1":"0")}catch(e){}})})})()</script>';
}

/** Small round avatar for another person; the colour follows the person (id), so the same friend keeps one colour. */
function shared_avatar_for(int $id, string $name): string
{
    return shared_avatar($name, $id % 6);
}
