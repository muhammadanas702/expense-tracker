<?php
/** SVG icon paths (24x24 stroke icons) taken from the v6 design. */
function ef_icon_paths(): array
{
    return [
        'home' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'inc' => '<path d="M17 7 7 17M17 17H7V7"/>',
        'exp' => '<path d="M7 7h10v10M7 17 17 7"/>',
        'doc' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
        'usr' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'act' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'grid' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'pl' => '<path d="M12 5v14M5 12h14"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a2 2 0 0 0 3.4 0"/>',
        'out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'wal' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'food' => '<path d="M3 2v7a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6a2 2 0 0 0 2 2h3zm0 0v7"/>',
        'car' => '<path d="M19 17h2a1 1 0 0 0 1-1v-3a2 2 0 0 0-1.5-1.9L16 10l-2.2-2.3a2 2 0 0 0-1.8-.7H5a1 1 0 0 0-.9.9L2.700 11A4 4 0 0 0 2 12v4a1 1 0 0 0 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
        'zap' => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
        'bag' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'case' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'lap' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7M7.500 8a2.500 2.500 0 0 1 0-5C11 3 12 8 12 8s1-5 4.500-5a2.500 2.500 0 0 1 0 5"/>',
        'heart' => '<path d="M19 14c1.500-1.500 3-3.200 3-5.500A5.500 5.500 0 0 0 16.500 3c-1.800 0-3 .5-4.500 2-1.500-1.500-2.700-2-4.500-2A5.500 5.500 0 0 0 2 8.500c0 2.300 1.500 4 3 5.500l7 7z"/>',
        'book' => '<path d="M4 19.500v-15A2.500 2.500 0 0 1 6.500 2H20v20H6.500a2.500 2.500 0 0 1 0-5H20"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'cal' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'tgt' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'bulb' => '<path d="M15 14c.2-1 .7-1.700 1.500-2.500 1-.9 1.500-2.200 1.500-3.500A6 6 0 0 0 6 8c0 1 .2 2.200 1.500 3.500.7.7 1.300 1.500 1.500 2.500M9 18h6M10 22h4"/>',
        'up' => '<path d="m22 7-8.500 8.500-5-5L2 17M16 7h6v6"/>',
        'dn' => '<path d="m22 17-8.500-8.500-5 5L2 7M16 17h6v-6"/>',
        'tag' => '<path d="M12.600 2.600A2 2 0 0 0 11.200 2H4a2 2 0 0 0-2 2v7.200c0 .5.200 1 .6 1.400l8.700 8.700a2 2 0 0 0 2.800 0l6.600-6.600a2 2 0 0 0 0-2.800z"/><circle cx="7.500" cy="7.500" r=".5"/>',
        'shield' => '<path d="M20 13c0 5-3.500 7.500-8 9-4.500-1.500-8-4-8-9V5l8-3 8 3z"/><path d="m9 12 2 2 4-4"/>',
        'dl' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'rcp' => '<path d="M4 2v20l3-2 3 2 3-2 3 2 3-2V2l-3 2-3-2-3 2-3-2zM8 8h8M8 12h8"/>',
        'rpt' => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        'pen' => '<path d="M17 3a2.800 2.800 0 0 1 4 4L7.500 20.500 2 22l1.500-5.500z"/>',
        'tr' => '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'chev' => '<path d="m9 18 6-6-6-6"/>',
        'msg' => '<path d="M7.900 20A9 9 0 1 0 4 16.100L2 22z"/>',
        'warn' => '<path d="m21.700 18-8-14a2 2 0 0 0-3.500 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.700-3M12 9v4M12 17h.01"/>',
        'ok' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'mtn' => '<path d="m8 3 4 8 5-5 5 15H2z"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.500.5l3-3a5 5 0 0 0-7-7l-1.700 1.700M14 11a5 5 0 0 0-7.500-.5l-3 3a5 5 0 0 0 7 7l1.700-1.700"/>',
        'spark' => '<path d="m12 3-1.900 5.800L4 10l6.100 1.900L12 18l1.900-6.100L20 10l-6.100-1.200zM19 3v4M21 5h-4"/>',
        'srch' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.300-4.300"/>',
        'phone' => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        'dot' => '<circle cx="12" cy="12" r="5" fill="currentColor"/>',
        'hand' => '<path d="M18 11V6a2 2 0 0 0-4 0M14 10V4a2 2 0 0 0-4 0v2M10 10.500V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.800 0-4.500-.9-5.700-2.400L3.400 16a2 2 0 0 1 3.200-2.400L8 15"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20"/>',
    ];
}

function ef_icon(string $name, string $color = ''): string
{
    static $p = null;
    $p ??= ef_icon_paths();
    if (!isset($p[$name])) {
        return '';
    }
    $style = $color !== '' && preg_match('/^#[0-9a-f]{3,6}$/i', $color) ? ' style="color:' . $color . '"' : '';
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"' . $style . '>' . $p[$name] . '</svg>';
}
