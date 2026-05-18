<?php

namespace App\Support;

final class EmailLogoHtml
{
    public static function wrap(string $logoUrl): string
    {
        return '<div data-email-logo="1" style="text-align:center;margin-bottom:20px">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;background-color:#ffffff">'
            .'<tr><td bgcolor="#ffffff" style="padding:12px 16px;background-color:#ffffff;border-radius:8px">'
            .'<img src="'.e($logoUrl).'" alt="Logo" width="240" style="max-height:60px;max-width:240px;width:auto;height:auto;display:block;margin:0 auto;border:0;outline:none;text-decoration:none" />'
            .'</td></tr></table></div>';
    }
}
