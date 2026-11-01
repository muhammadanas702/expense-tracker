<?php
/**
 * Light / dark theme. The choice (auto, light, dark) is kept per device in the "ef_theme" cookie and applied by a tiny
 * script in <head> BEFORE the page paints, so there is no flash. "auto" follows the device. assets/theme-dark.css holds
 * the dark colours; settings/preferences.php lets the person choose.
 */
function ef_theme_head(): string
{
    return '<link rel="stylesheet" href="' . e(ef_asset('assets/theme-dark.css')) . '">'
        . '<script>(function(){try{var m=document.cookie.match(/(?:^|; )ef_theme=(auto|light|dark)/),t=m?m[1]:"auto",'
        . 'd=t==="dark"||(t==="auto"&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches);'
        . 'if(d){document.documentElement.setAttribute("data-theme","dark");}}catch(e){}})();</script>';
}
