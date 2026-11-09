<?php

namespace Mailing\Editor;

/**
 * Converteix un correu del Mailing (MailDoc) en l'HTML que s'envia.
 *
 * Fet per a tots els programes de correu, Outlook per a Windows inclòs (dibuixa
 * amb Word): taules de 600 px, estils escrits a cada element, taula «fantasma»
 * per a Outlook, botons VML amb cantonades arrodonides i columnes que s'apilen
 * soles al mòbil (inline-block), fins i tot on no es llegeixen les regles CSS.
 *
 * Les imatges es resolen amb $image(src): adreça pública (previsualització,
 * HTML, Outlook Web) o «cid:…» (fitxers .oft i .emltpl, amb la imatge a dins).
 */
class MailRenderer
{
    protected array $s;

    protected string $font;

    /** @var callable(string): ?string */
    protected $image;

    /** Idioma del correu (atribut lang). */
    public string $lang = 'ca';

    /** Files afegides al final (peu legal amb l'enllaç de baixa). */
    public string $tail = '';

    /** Amplada útil (600 menys els marges laterals). */
    protected int $inner;

    /**
     * @param  callable(string): ?string  $image
     */
    public function __construct(callable $image)
    {
        $this->image = $image;
    }

    public function html(array $doc, string $title, string $preheader = ''): string
    {
        $doc = MailDoc::clean($doc);
        $this->s = $doc['settings'];
        $this->font = MailDoc::FONTS[$this->s['font']]['stack'];
        $this->inner = MailDoc::WIDTH - 2 * $this->s['padX'];

        $rows = '';
        foreach ($doc['blocks'] as $block) {
            $rows .= $this->block($block);
        }

        $bodyBg = $this->s['bodyBg'];
        $contentBg = $this->s['contentBg'];
        $pre = $preheader !== ''
            ? '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;color:' . $bodyBg . ';">' . $this->e($preheader)
                . str_repeat('&#847;&zwnj;&nbsp;', 30) . '</div>'
            : '';

        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="' . $this->lang . '">' . "\n"
            . '<head>' . "\n"
            . '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta http-equiv="X-UA-Compatible" content="IE=edge">' . "\n"
            . '<meta name="x-apple-disable-message-reformatting">' . "\n"
            . '<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">' . "\n"
            . '<meta name="color-scheme" content="light">' . "\n"
            . '<meta name="supported-color-schemes" content="light">' . "\n"
            . '<title>' . $this->e($title) . '</title>' . "\n"
            . '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->' . "\n"
            . '<style type="text/css">' . $this->css() . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body style="margin:0;padding:0;width:100%;background-color:' . $bodyBg . ';-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">' . "\n"
            . $pre . "\n"
            . '<center role="article" aria-roledescription="email" lang="' . $this->lang . '" style="width:100%;background-color:' . $bodyBg . ';">' . "\n"
            . '<!--[if mso]><table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;"><tr><td><![endif]-->' . "\n"
            . '<table role="presentation" class="wrap" align="center" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;margin:0 auto;background-color:' . $contentBg . ';border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;">' . "\n"
            . $rows . $this->tail
            . '</table>' . "\n"
            . '<!--[if mso]></td></tr></table><![endif]-->' . "\n"
            . '</center>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /** Versió de text pla (correus de prova i fitxers exportats). */
    public function text(array $doc, string $title): string
    {
        $doc = MailDoc::clean($doc);
        $out = [];
        foreach ($doc['blocks'] as $b) {
            $part = match ($b['type']) {
                'heading' => mb_strtoupper($b['text']),
                'text', 'box' => RichText::text($b['html']),
                'button', 'link' => trim($b['text'] . ($b['href'] !== '' ? ': ' . $b['href'] : '')),
                'image' => $b['alt'],
                'columns' => implode("\n\n", array_map(fn ($c) => $this->colText($c), $b['cols'])),
                'imagetext' => $this->colText($b),
                'divider' => '----------',
            'event' => trim(mb_strtoupper($b['title']) . "\n" . $b['when'] . ($b['location'] !== '' ? "\n" . $b['location'] : '') . ($b['desc'] !== '' ? "\n\n" . $b['desc'] : '') . ($b['href'] !== '' ? "\n" . $b['btnText'] . ': ' . $b['href'] : '')),
            'post' => trim(mb_strtoupper($b['title']) . ($b['desc'] !== '' ? "\n" . $b['desc'] : '') . (($b['ends'] ?? '') !== '' ? "\n" . $b['ends'] : '') . ($b['href'] !== '' ? "\n" . $b['btnText'] . ': ' . $b['href'] : '')),
                'footer' => trim($b['name'] . "\n" . implode(' · ', array_map(fn ($l) => $l['text'] . ($l['href'] !== '' ? ' (' . $l['href'] . ')' : ''), $b['links'])) . ($b['note'] !== '' ? "\n\n" . $b['note'] : '')),
                default => '',
            };
            if (trim((string) $part) !== '') {
                $out[] = trim((string) $part);
            }
        }

        return trim(implode("\n\n", $out)) . "\n";
    }

    protected function colText(array $c): string
    {
        return trim(($c['title'] !== '' ? mb_strtoupper($c['title']) . "\n" : '') . RichText::text($c['html'])
            . ($c['linkText'] !== '' ? "\n" . $c['linkText'] . ($c['href'] !== '' ? ': ' . $c['href'] : '') : ''));
    }

    // ------------------------------------------------------------------

    protected function css(): string
    {
        return 'body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}'
            . 'table,td{mso-table-lspace:0pt;mso-table-rspace:0pt;}'
            . 'img{-ms-interpolation-mode:bicubic;border:0;outline:none;text-decoration:none;}'
            . 'a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;}'
            . '#MessageViewBody a{color:inherit;text-decoration:none;}'
            . 'p{margin:0;}'
            . '@media only screen and (max-width:620px){'
            . '.wrap{width:100%!important;max-width:100%!important;}'
            . '.px{padding-left:16px!important;padding-right:16px!important;}'
            . '.col{display:block!important;width:100%!important;max-width:100%!important;}'
            . '.col-in{padding-left:0!important;padding-right:0!important;padding-bottom:18px!important;}'
            . '.img-f{width:100%!important;max-width:100%!important;height:auto!important;}'
            . '.btn-m{display:block!important;width:auto!important;}'
            . '.hide-m{display:none!important;max-height:0!important;overflow:hidden!important;mso-hide:all!important;}'
            . '}';
    }

    protected function block(array $b): string
    {
        $inner = match ($b['type']) {
            'header' => $this->header($b),
            'heading' => $this->heading($b),
            'text' => $this->rich($b['html'], $b['size'], $b['color'], $b['align']),
            'image' => $this->imageBlock($b),
            'button' => $this->button($b),
            'link' => $this->link($b),
            'box' => $this->box($b),
            'columns' => $this->columns($b),
            'imagetext' => $this->imageText($b),
            'divider' => $this->divider($b),
            'spacer' => '<div style="height:' . $b['height'] . 'px;line-height:' . $b['height'] . 'px;font-size:' . min($b['height'], 50) . 'px;mso-line-height-rule:exactly;">&nbsp;</div>',
            'footer' => $this->footer($b),
            'event' => $this->eventCard($b),
            'post' => $this->postCard($b),
            default => '',
        };
        if ($inner === null || $inner === '') {
            return '';
        }

        $padX = $this->s['padX'];
        $pad = 'padding:' . $b['pt'] . 'px ' . $padX . 'px ' . $b['pb'] . 'px ' . $padX . 'px;';
        $align = in_array($b['type'], ['header', 'image', 'button', 'link', 'heading', 'text'], true) ? ($b['align'] ?? 'left') : 'left';
        // Blocs amb columnes (cada columna porta 10 px de marge interior a cada costat).
        $hybrid = in_array($b['type'], ['columns', 'imagetext'], true) || ($b['type'] === 'post' && $b['layout'] === 'side' && $b['src'] !== '');
        if ($hybrid) {
            // Les columnes porten el seu propi marge interior (10 px a cada costat).
            $pad = 'padding:' . $b['pt'] . 'px ' . max(0, $padX - 10) . 'px ' . $b['pb'] . 'px ' . max(0, $padX - 10) . 'px;';
        }
        $border = $b['type'] === 'header' && $b['borderTop'] !== '' && $b['borderWidth'] > 0
            ? 'border-top:' . $b['borderWidth'] . 'px solid ' . $b['borderTop'] . ';' : '';
        $bg = $b['bg'] !== '' ? 'background-color:' . $b['bg'] . ';' : '';
        $trClass = $b['hideMobile'] ? ' class="hide-m"' : '';
        $tdClass = ' class="px"';
        $extraTd = $hybrid ? 'font-size:0;' : '';
        $bgAttr = $b['bg'] !== '' ? ' bgcolor="' . $b['bg'] . '"' : '';

        return '<tr' . $trClass . '><td' . $tdClass . ' align="' . $align . '"' . $bgAttr . ' style="' . $pad . $border . $bg . $extraTd . '">' . $inner . "</td></tr>\n";
    }

    protected function header(array $b): string
    {
        $img = $this->img($b['src'], $b['alt'], $b['width'], false);
        if ($img === '') {
            return '<div style="font-size:1px;line-height:1px;">&nbsp;</div>';
        }

        return $b['href'] !== '' ? '<a href="' . $this->e($b['href']) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>' : $img;
    }

    protected function heading(array $b): string
    {
        if ($b['text'] === '') {
            return '';
        }
        $color = $b['color'] !== '' ? $b['color'] : ($b['level'] === 2 ? $this->s['headingColor'] : '#333333');
        $lh = (int) round($b['size'] * 1.4);
        $tag = $b['level'] === 2 ? 'h2' : 'h1';

        return '<' . $tag . ' style="margin:0;font-family:' . $this->fontAttr() . ';font-size:' . $b['size'] . 'px;line-height:' . $lh . 'px;font-weight:bold;color:' . $color
            . ';text-align:' . $b['align'] . ';mso-line-height-rule:exactly;">' . $this->e($b['text']) . '</' . $tag . '>';
    }

    /** Text amb format: cada paràgraf i element porta els seus estils (Outlook no els hereta). */
    protected function rich(string $html, int $size = 0, string $color = '', string $align = 'left'): string
    {
        if (trim($html) === '') {
            return '';
        }
        $size = $size ?: $this->s['textSize'];
        $color = $color ?: $this->s['textColor'];
        $lh = (int) round($size * 1.45);
        $base = 'font-family:' . $this->fontAttr() . ';font-size:' . $size . 'px;line-height:' . $lh . 'px;color:' . $color . ';mso-line-height-rule:exactly;';
        $link = $this->s['linkColor'];
        $headSize = $size + 3;

        $out = preg_replace_callback('#<a href="([^"]*)"[^>]*>#', fn ($m) => '<a href="' . $m[1] . '" target="_blank" style="color:' . $link . ';text-decoration:underline;">', $html);
        $out = preg_replace_callback('#<(p|li|h3|h4|ul|ol|blockquote)>#', function ($m) use ($base, $align, $headSize, $size): string {
            return match ($m[1]) {
                'p' => '<p style="margin:0 0 ' . round($size * 0.8) . 'px 0;' . $base . 'text-align:' . $align . ';">',
                'li' => '<li style="margin:0 0 4px 0;' . $base . '">',
                'ul', 'ol' => '<' . $m[1] . ' style="margin:0 0 ' . round($size * 0.8) . 'px 0;padding:0 0 0 24px;' . $base . '">',
                'h3', 'h4' => '<p style="margin:0 0 8px 0;' . $base . 'font-size:' . ($m[1] === 'h3' ? $headSize : $size) . 'px;font-weight:bold;color:' . $this->s['headingColor'] . ';text-align:' . $align . ';">',
                'blockquote' => '<blockquote style="margin:0 0 ' . round($size * 0.8) . 'px 0;padding:0 0 0 14px;border-left:3px solid #e0e2e6;' . $base . '">',
            };
        }, (string) $out);
        $out = str_replace(['</h3>', '</h4>'], '</p>', (string) $out);
        // L'últim paràgraf (o llista) sense marge a baix.
        if (preg_match_all('#<(?:p|ul|ol|blockquote) style="margin:0 0 \d+px 0;#', (string) $out, $m, PREG_OFFSET_CAPTURE) && $m[0]) {
            [$last, $pos] = end($m[0]);
            $out = substr_replace((string) $out, preg_replace('#margin:0 0 \d+px 0;#', 'margin:0;', $last), $pos, strlen($last));
        }

        return '<div style="' . $base . 'text-align:' . $align . ';">' . $out . '</div>';
    }

    protected function imageBlock(array $b): ?string
    {
        $width = $b['width'] > 0 ? min($b['width'], $this->inner) : $this->inner;
        $img = $this->img($b['src'], $b['alt'], $width, $width >= $this->inner - 40, $b['radius']);
        if ($img === '') {
            return null;
        }

        return $b['href'] !== '' ? '<a href="' . $this->e($b['href']) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>' : $img;
    }

    protected function button(array $b): ?string
    {
        if ($b['text'] === '') {
            return null;
        }
        $bg = $b['btnBg'] !== '' ? $b['btnBg'] : $this->s['accent'];
        $href = $b['href'] !== '' ? $b['href'] : '#';
        $height = (int) round($b['size'] * 2.9);
        $width = $b['full'] ? $this->inner : min($this->inner, max(140, (int) round(mb_strlen($b['text']) * $b['size'] * 0.6 + 56)));
        $arc = $b['radius'] > 0 ? min(50, (int) round($b['radius'] * 100 / $height)) : 0;
        $text = $this->e($b['text']);
        $style = 'background-color:' . $bg . ';border-radius:' . $b['radius'] . 'px;color:' . $b['color'] . ';display:' . ($b['full'] ? 'block' : 'inline-block')
            . ';font-family:' . $this->fontAttr() . ';font-size:' . $b['size'] . 'px;font-weight:bold;line-height:' . $height . 'px;text-align:center;text-decoration:none;'
            . ($b['full'] ? '' : 'padding:0 28px;') . '-webkit-text-size-adjust:none;mso-hide:all;';

        return '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $this->e($href) . '" style="height:' . $height . 'px;v-text-anchor:middle;width:' . $width . 'px;" arcsize="' . $arc . '%" stroke="f" fillcolor="' . $bg . '"><w:anchorlock/>'
            . '<center style="color:' . $b['color'] . ';font-family:' . $this->fontAttr() . ';font-size:' . $b['size'] . 'px;font-weight:bold;">' . $text . '</center></v:roundrect><![endif]-->'
            . '<!--[if !mso]><!-- --><a href="' . $this->e($href) . '" target="_blank"' . ($b['mobileFull'] ? ' class="btn-m"' : '') . ' style="' . $style . '">' . $text . '</a><!--<![endif]-->';
    }

    protected function link(array $b): ?string
    {
        if ($b['text'] === '') {
            return null;
        }
        $size = $b['size'] ?: $this->s['textSize'];
        $color = $b['color'] !== '' ? $b['color'] : $this->s['linkColor'];
        $text = $this->e($b['text']);
        $inner = $b['bold'] ? '<b>' . $text . '</b>' : $text;
        $p = 'margin:0;font-family:' . $this->fontAttr() . ';font-size:' . $size . 'px;line-height:' . round($size * 1.45) . 'px;text-align:' . $b['align'] . ';mso-line-height-rule:exactly;';

        return '<p style="' . $p . '">' . ($b['href'] !== ''
            ? '<a href="' . $this->e($b['href']) . '" target="_blank" style="color:' . $color . ';text-decoration:none;">' . $inner . '</a>'
            : '<span style="color:' . $color . ';">' . $inner . '</span>') . '</p>';
    }

    protected function box(array $b): ?string
    {
        $content = $this->rich($b['html'], $b['size'], $b['color']);
        if ($content === '') {
            return null;
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;"><tr>'
            . '<td bgcolor="' . $b['boxBg'] . '" style="background-color:' . $b['boxBg'] . ';border-radius:' . $b['radius'] . 'px;padding:16px 20px;">' . $content . '</td></tr></table>';
    }

    /** Columnes «híbrides»: costat a costat a l'ordinador, una sota l'altra al mòbil. */
    protected function columns(array $b): string
    {
        $n = $b['count'];
        $total = $this->inner + 20;
        $w = (int) floor($total / $n);
        $cells = [];
        foreach ($b['cols'] as $c) {
            $cells[] = $this->colContent($c, $w - 20);
        }

        return $this->hybrid($cells, array_fill(0, $n, $w));
    }

    protected function imageText(array $b): string
    {
        $total = $this->inner + 20;
        $wi = (int) floor($total * 0.45);
        $wt = $total - $wi;
        $img = $this->img($b['src'], $b['alt'], $wi - 20, true);
        $text = $this->colContent(['src' => ''] + $b, $wt - 20);
        $imgCell = $img !== '' ? ($b['href'] !== '' ? '<a href="' . $this->e($b['href']) . '" target="_blank">' . $img . '</a>' : $img) : '';
        if ($imgCell === '') {
            // Sense imatge: el text ocupa tota l'amplada.
            return $this->hybrid([$this->colContent(['src' => ''] + $b, $total - 20)], [$total]);
        }

        // Imatge a la dreta: a l'ordinador es giren les columnes, al mòbil la imatge queda a dalt.
        return $b['side'] === 'right'
            ? $this->hybrid([$imgCell, $text], [$wi, $wt], true)
            : $this->hybrid([$imgCell, $text], [$wi, $wt]);
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<int, int>  $widths
     */
    protected function hybrid(array $cells, array $widths, bool $rtl = false): string
    {
        $dir = $rtl ? ' dir="rtl"' : '';
        $out = '<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"' . $dir . '><tr><![endif]-->';
        foreach ($cells as $i => $cell) {
            $w = $widths[$i];
            $out .= '<!--[if mso]><td width="' . $w . '" valign="top" style="width:' . $w . 'px;" dir="ltr"><![endif]-->'
                . '<div class="col" dir="ltr" style="display:inline-block;width:100%;max-width:' . $w . 'px;vertical-align:top;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td class="col-in" valign="top" style="padding:0 10px;font-family:' . $this->fontAttr() . ';font-size:' . $this->s['textSize'] . 'px;text-align:left;">' . ($cell !== '' ? $cell : '&nbsp;') . '</td>'
                . '</tr></table></div>'
                . '<!--[if mso]></td><![endif]-->';
        }
        $out .= '<!--[if mso]></tr></table><![endif]-->';

        if ($rtl) {
            return '<div dir="rtl" style="text-align:left;font-size:0;">' . $out . '</div>';
        }

        return $out;
    }

    protected function colContent(array $c, int $width): string
    {
        $out = '';
        $img = $this->img($c['src'] ?? '', $c['alt'] ?? '', $width, true);
        if ($img !== '') {
            $img = ($c['href'] ?? '') !== '' ? '<a href="' . $this->e($c['href']) . '" target="_blank">' . $img . '</a>' : $img;
            $out .= '<div style="margin:0 0 12px 0;">' . $img . '</div>';
        }
        if (($c['title'] ?? '') !== '') {
            $size = $this->s['textSize'] + 2;
            $out .= '<p style="margin:0 0 6px 0;font-family:' . $this->fontAttr() . ';font-size:' . $size . 'px;line-height:' . round($size * 1.35) . 'px;font-weight:bold;color:'
                . $this->s['headingColor'] . ';mso-line-height-rule:exactly;">' . $this->e($c['title']) . '</p>';
        }
        $out .= $this->rich($c['html'] ?? '');
        if (($c['linkText'] ?? '') !== '') {
            $out .= '<div style="height:8px;line-height:8px;font-size:8px;">&nbsp;</div>'
                . $this->link(['text' => $c['linkText'], 'href' => $c['href'] ?? '', 'color' => '', 'bold' => true, 'align' => 'left', 'size' => 0]);
        }

        return $out;
    }

    /** Paràgraf de text pla (els salts de línia es conserven). */
    protected function para(string $text, int $size, string $color, string $extra = '', string $margin = '0 0 6px 0'): string
    {
        if ($text === '') {
            return '';
        }

        return '<p style="margin:' . $margin . ';font-family:' . $this->fontAttr() . ';font-size:' . $size . 'px;line-height:' . round($size * 1.45) . 'px;color:' . $color
            . ';mso-line-height-rule:exactly;' . $extra . '">' . nl2br($this->e($text), false) . '</p>';
    }

    /** Esdeveniment del Calendari: data destacada, títol, quan, on i descripció. */
    protected function eventCard(array $b): ?string
    {
        if ($b['title'] === '') {
            return null;
        }
        $accent = $this->s['accent'];
        $badge = '';
        if ($b['day'] !== '') {
            $badge = '<td width="64" valign="top" style="width:64px;padding:0 16px 0 0;">'
                . '<table role="presentation" width="64" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="' . $accent . '" style="background-color:' . $accent . ';border-radius:10px;padding:8px 0;">'
                . '<p style="margin:0;font-family:' . $this->fontAttr() . ';font-size:24px;line-height:26px;font-weight:bold;color:#ffffff;mso-line-height-rule:exactly;">' . $this->e($b['day']) . '</p>'
                . '<p style="margin:0;font-family:' . $this->fontAttr() . ';font-size:11px;line-height:14px;font-weight:bold;letter-spacing:1px;color:#ffffff;mso-line-height-rule:exactly;">' . $this->e(mb_strtoupper($b['month'])) . '</p>'
                . '</td></tr></table></td>';
        }
        $body = $this->para($b['title'], $this->s['textSize'] + 2, '#1f2430', 'font-weight:bold;')
            . $this->para($b['when'], $this->s['textSize'] - 1, $this->s['headingColor'], 'font-weight:bold;', '0 0 2px 0')
            . $this->para($b['location'], $this->s['textSize'] - 1, '#4b5260', '', '0 0 8px 0')
            . $this->para($b['desc'], $this->s['textSize'] - 1, $this->s['textColor'], '', '6px 0 0 0');
        if ($b['href'] !== '' && $b['btnText'] !== '') {
            $body .= '<div style="height:8px;line-height:8px;font-size:8px;">&nbsp;</div>'
                . $this->link(['text' => $b['btnText'], 'href' => $b['href'], 'color' => '', 'bold' => true, 'align' => 'left', 'size' => $this->s['textSize'] - 1]);
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;"><tr>'
            . '<td bgcolor="' . $b['boxBg'] . '" style="background-color:' . $b['boxBg'] . ';border-radius:12px;padding:16px 18px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $badge . '<td valign="top">' . $body . '</td></tr></table>'
            . '</td></tr></table>';
    }

    /** Enquesta o formulari del web: requadre amb el títol, el text, el termini i el botó. */

    /** Publicació del web: imatge, data, títol, resum i enllaç. */
    protected function postCard(array $b): ?string
    {
        if ($b['title'] === '') {
            return null;
        }
        $title = $b['href'] !== ''
            ? '<a href="' . $this->e($b['href']) . '" target="_blank" style="color:#1f2430;text-decoration:none;">' . $this->e($b['title']) . '</a>'
            : $this->e($b['title']);
        $size = $this->s['textSize'] + 2;
        $text = $this->para($b['date'], 12, '#6b7280', 'font-weight:bold;letter-spacing:.5px;', '0 0 4px 0')
            . '<p style="margin:0 0 6px 0;font-family:' . $this->fontAttr() . ';font-size:' . $size . 'px;line-height:' . round($size * 1.35) . 'px;font-weight:bold;color:#1f2430;mso-line-height-rule:exactly;">' . $title . '</p>'
            . $this->para($b['desc'], $this->s['textSize'] - 1, $this->s['textColor']);
        if ($b['href'] !== '' && $b['btnText'] !== '') {
            $text .= '<div style="height:6px;line-height:6px;font-size:6px;">&nbsp;</div>'
                . $this->link(['text' => $b['btnText'], 'href' => $b['href'], 'color' => '', 'bold' => true, 'align' => 'left', 'size' => $this->s['textSize'] - 1]);
        }

        if ($b['layout'] === 'side' && $b['src'] !== '') {
            $total = $this->inner + 20;
            $wi = (int) floor($total * 0.4);
            $img = $this->img($b['src'], $b['alt'] ?: $b['title'], $wi - 20, true, 10);
            if ($b['href'] !== '' && $img !== '') {
                $img = '<a href="' . $this->e($b['href']) . '" target="_blank">' . $img . '</a>';
            }

            return $this->hybrid([$img, $text], [$wi, $total - $wi]);
        }

        $img = $b['src'] !== '' ? $this->img($b['src'], $b['alt'] ?: $b['title'], $this->inner, true, 12) : '';
        if ($img !== '' && $b['href'] !== '') {
            $img = '<a href="' . $this->e($b['href']) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>';
        }

        return ($img !== '' ? '<div style="margin:0 0 12px 0;">' . $img . '</div>' : '') . $text;
    }

    protected function divider(array $b): string
    {
        return '<table role="presentation" align="center" width="' . $b['widthPct'] . '%" cellpadding="0" cellspacing="0" border="0" style="width:' . $b['widthPct'] . '%;"><tr>'
            . '<td style="border-top:' . $b['thickness'] . 'px solid ' . $b['color'] . ';font-size:1px;line-height:1px;height:1px;">&nbsp;</td></tr></table>';
    }

    protected function footer(array $b): string
    {
        $out = '';
        if ($b['lineColor'] !== '') {
            $out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-bottom:1px solid ' . $b['lineColor'] . ';font-size:1px;line-height:1px;height:1px;">&nbsp;</td></tr></table>'
                . '<div style="height:16px;line-height:16px;font-size:16px;">&nbsp;</div>';
        }
        $img = $b['width'] > 0 ? $this->img($b['src'], $b['alt'], $b['width'], false) : '';
        if ($img !== '') {
            $out .= '<div style="text-align:center;">' . $img . '</div><div style="height:10px;line-height:10px;font-size:10px;">&nbsp;</div>';
        }
        $p = 'margin:0;font-family:' . $this->fontAttr() . ';font-size:12px;line-height:18px;text-align:center;mso-line-height-rule:exactly;';
        if ($b['name'] !== '') {
            $out .= '<p style="' . $p . 'color:' . $b['color'] . ';">' . $this->e($b['name']) . '</p><div style="height:8px;line-height:8px;font-size:8px;">&nbsp;</div>';
        }
        if ($b['links']) {
            $linkColor = $b['linkColor'] !== '' ? $b['linkColor'] : $this->s['linkColor'];
            $parts = array_map(fn ($l) => $l['href'] !== ''
                ? '<a href="' . $this->e($l['href']) . '" target="_blank" style="color:' . $linkColor . ';text-decoration:none;">' . $this->e($l['text']) . '</a>'
                : '<span style="color:' . $linkColor . ';">' . $this->e($l['text']) . '</span>', $b['links']);
            $out .= '<p style="' . $p . 'color:#333333;">' . implode('<span style="color:#333333;">&nbsp;&nbsp;·&nbsp;&nbsp;</span>', $parts) . '</p>';
        }
        if ($b['note'] !== '') {
            $out .= '<div style="height:12px;line-height:12px;font-size:12px;">&nbsp;</div><p style="' . $p . 'font-size:11px;line-height:16px;color:' . $b['color'] . ';">' . nl2br($this->e($b['note'])) . '</p>';
        }

        return $out;
    }

    /** <img> amb amplada fixa (Outlook) i adaptable (la resta). Buit si no hi ha imatge. */
    protected function img(string $src, string $alt, int $width, bool $fluid, int $radius = 0): string
    {
        if ($src === '') {
            return '';
        }
        $url = ($this->image)($src);
        if (! $url) {
            return '';
        }
        $width = max(1, $width);

        return '<img src="' . $this->e($url) . '" alt="' . $this->e($alt) . '" width="' . $width . '"' . ($fluid ? ' class="img-f"' : '')
            . ' style="display:inline-block;width:' . $width . 'px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;'
            . ($radius > 0 ? 'border-radius:' . $radius . 'px;' : '') . 'font-family:' . $this->fontAttr() . ';font-size:13px;color:#666666;">';
    }

    /** Tipus de lletra dins d'un atribut style (cometes simples). */
    protected function fontAttr(): string
    {
        return $this->font;
    }

    protected function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }
}
