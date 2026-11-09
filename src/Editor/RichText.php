<?php

namespace Mailing\Editor;

/**
 * Text amb format de l'editor visual (camp «Contingut» de les tasques).
 *
 * L'HTML que arriba del navegador NO és de fiar: es reconstrueix només amb una
 * llista tancada d'etiquetes de format, sense cap atribut excepte l'enllaç
 * (http, https o mailto) dels <a>. Tota la resta (scripts, estils, imatges,
 * esdeveniments, iframes…) es descarta; el text sempre es conserva.
 *
 * Mode «ric» (Notes): també línies horitzontals, taules (files i cel·les,
 * sense atributs) i imatges, però només les pujades a les notes (adreça
 * tauler.notes.image amb el codi de 40 caràcters).
 */
class RichText
{
    /** Etiqueta acceptada => etiqueta amb què es desa. */
    protected const TAGS = [
        'p' => 'p', 'div' => 'p', 'br' => 'br',
        'strong' => 'strong', 'b' => 'strong',
        'em' => 'em', 'i' => 'em',
        'u' => 'u',
        's' => 's', 'strike' => 's', 'del' => 's',
        'h1' => 'h3', 'h2' => 'h3', 'h3' => 'h3', 'h4' => 'h4', 'h5' => 'h4', 'h6' => 'h4',
        'ul' => 'ul', 'ol' => 'ol', 'li' => 'li',
        'blockquote' => 'blockquote',
        'a' => 'a',
    ];

    /** Elements que es descarten amb tot el que contenen. */
    protected const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'head', 'title', 'meta', 'link', 'form', 'input', 'button', 'select', 'textarea', 'img', 'video', 'audio'];

    public const MAX_LENGTH = 100000;

    protected const MAX_COLS = 20;
    protected const MAX_ROWS = 300;

    /** Mida de les imatges al text (per defecte, tot l'ample). */
    public const IMAGE_SIZES = ['s', 'm'];

    protected static bool $rich = false;
    protected static int $tableDepth = 0;

    /** HTML net (o null si no queda text). $rich: imatges, línies i taules (Notes). */
    public static function clean(?string $html, bool $rich = false): ?string
    {
        $before = self::$rich;
        self::$rich = $rich;
        try {
            return self::doClean($html, $rich);
        } finally {
            self::$rich = $before;
        }
    }

    /** Adreça d'una imatge de les notes (la mateixa que reconeix clean()). */
    public static function imageUrl(string $token): string
    {
        return '/img/' . $token;
    }

    /** Codis de les imatges de les notes que surten al text. @return array<int, string> */
    public static function imageTokens(string $html): array
    {
        if ($html === '' || ! str_contains($html, '<img')) {
            return [];
        }
        $re = self::imagePathPattern();
        preg_match_all('#<img[^>]+src="([^"]+)"#', $html, $m);
        $tokens = [];
        foreach ($m[1] as $src) {
            $path = (string) parse_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH);
            if (preg_match($re, $path, $t)) {
                $tokens[] = $t[1];
            }
        }

        return array_values(array_unique($tokens));
    }

    /** Patró del camí de les imatges (a partir de la ruta, sigui quin sigui el prefix). */
    protected static function imagePathPattern(): string
    {
        static $re = [];
        $marker = str_repeat('X', 40);
        $path = (string) parse_url(self::imageUrl($marker), PHP_URL_PATH);

        return $re[$path] ??= '#^' . str_replace(preg_quote($marker, '#'), '([A-Za-z0-9]{40})', preg_quote($path, '#')) . '$#';
    }

    protected static function doClean(?string $html, bool $rich): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }
        $html = mb_substr($html, 0, self::MAX_LENGTH * 2);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="rt-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('rt-root');
        if (! $root) {
            return null;
        }

        $out = trim(self::children($root));
        // Paràgrafs i elements de llista buits (els deixa l'editor) fora.
        $out = preg_replace('#<(p|li|h3|h4|blockquote)>(\s|&nbsp;|<br>)*</\1>#u', '', $out);
        $out = trim((string) $out);
        $hasObjects = $rich && preg_match('#<(img|hr|table)[\s>]#', $out);

        return trim(strip_tags($out)) === '' && ! $hasObjects ? null : mb_substr($out, 0, self::MAX_LENGTH);
    }

    /** Text pla (versió de text del correu, cerca). */
    public static function text(?string $html): string
    {
        if (! $html) {
            return '';
        }
        $html = preg_replace('#<li>#', '• ', $html);
        $html = preg_replace('#</t[dh]>#', "\t", (string) $html);
        $html = preg_replace('#<hr>#', "\n—————\n", (string) $html);
        $html = preg_replace('#<img [^>]*>#', '[imatge] ', (string) $html);
        $html = preg_replace('#</(p|h3|h4|li|blockquote|ul|ol|tr|table)>|<br>#', "\n", (string) $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** Blocs que no es poden niuar (un paràgraf dins d'un altre es desfà). */
    protected const BLOCKS = ['p', 'h3', 'h4'];

    protected static function children(\DOMNode $node, bool $inBlock = false): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::node($child, $inBlock);
        }

        return $out;
    }

    protected static function node(\DOMNode $node, bool $inBlock = false): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
        }
        if (! $node instanceof \DOMElement) {
            return ''; // comentaris, instruccions…
        }

        $name = strtolower($node->nodeName);
        // Etiquetes amb prefix (o:p de Word, w:…): només el text.
        if (str_contains($name, ':') || ($node->prefix ?? '') !== '') {
            return self::children($node, $inBlock);
        }
        if (self::$rich) {
            if ($name === 'img') {
                return self::image($node);
            }
            // Color del text i marcador (color de fons): <span style="…">.
            if (in_array($name, ['span', 'font', 'mark'], true)) {
                return self::colored($node, self::children($node, $inBlock));
            }
            // Dins d'un paràgraf, el paràgraf es desfà (més avall).
            if ($name === 'hr') {
                return '<hr>';
            }
            if ($name === 'table' && self::$tableDepth === 0) {
                return self::table($node);
            }
        }
        if (in_array($name, self::DROP, true)) {
            return '';
        }
        $tag = self::TAGS[$name] ?? null;
        $isBlock = in_array($tag, self::BLOCKS, true);
        $inner = self::children($node, $inBlock || $isBlock);
        if ($isBlock && $inBlock) {
            return $inner;
        }
        if (! isset(self::TAGS[$name])) {
            // Etiqueta no admesa (span, font, table…): es queda el text.
            return in_array($name, ['tr', 'table', 'section', 'article', 'header', 'footer'], true) ? $inner . ' ' : $inner;
        }

        if ($tag === 'br') {
            return '<br>';
        }
        if ($tag === 'a') {
            $href = trim((string) $node->getAttribute('href'));
            if (! preg_match('#^(https?://|mailto:)#i', $href)) {
                return $inner;
            }

            return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML401, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>';
        }
        // Un paràgraf que conté llistes o altres blocs (Chrome ho fa) es desfà.
        if (($tag === 'p' && preg_match('#<(ul|ol|blockquote|h3|h4|hr|table)>#', $inner)) || (in_array($tag, ['h3', 'h4'], true) && preg_match('#<(hr|table)>#', $inner))) {
            return $inner;
        }
        // Un <li> fora de llista, o una llista sense elements, no té sentit.
        if (in_array($tag, ['ul', 'ol'], true) && ! str_contains($inner, '<li>')) {
            return $inner === '' ? '' : '<p>' . $inner . '</p>';
        }

        return '<' . $tag . '>' . $inner . '</' . $tag . '>';
    }

    /** Text amb color o marcador: només aquests dos estils, amb colors vàlids (#rrggbb). */
    protected static function colored(\DOMElement $node, string $inner): string
    {
        if ($inner === '') {
            return '';
        }
        $name = strtolower($node->nodeName);
        $style = strtolower((string) $node->getAttribute('style'));
        $color = preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/', $style, $m) ? self::color($m[1]) : null;
        $back = preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/', $style, $m) ? self::color($m[1]) : null;
        if ($name === 'font' && $color === null) {
            $color = self::color((string) $node->getAttribute('color'));
        }
        if ($name === 'mark' && $back === null) {
            $back = '#fff3a3';
        }
        $css = ($color ? 'color:' . $color . ';' : '') . ($back ? 'background-color:' . $back . ';' : '');

        return $css === '' ? $inner : '<span style="' . rtrim($css, ';') . '">' . $inner . '</span>';
    }

    /** «#abc», «#aabbcc» o «rgb(…)» → «#aabbcc» (la resta, null; el transparent també). */
    public static function color(string $value): ?string
    {
        $v = strtolower(trim(str_replace('!important', '', $value)));
        if (preg_match('/^#([0-9a-f]{3})$/', $v, $m)) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $v)) {
            return $v;
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/', $v, $m)) {
            if (isset($m[4]) && (float) $m[4] === 0.0) {
                return null;
            }

            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        return null;
    }

    /** Imatge pujada a una nota (cap altra): src normalitzat, text alternatiu i mida. */
    protected static function image(\DOMElement $node): string
    {
        $src = html_entity_decode(trim((string) $node->getAttribute('src')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = (string) parse_url($src, PHP_URL_PATH);
        if ($path === '' || ! preg_match(self::imagePathPattern(), $path, $m)) {
            return '';
        }
        $alt = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $node->getAttribute('alt'))), 0, 200);
        $size = (string) $node->getAttribute('data-size');

        return '<img src="' . htmlspecialchars(self::imageUrl($m[1]), ENT_QUOTES | ENT_HTML401, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_HTML401, 'UTF-8') . '"'
            . (in_array($size, self::IMAGE_SIZES, true) ? ' data-size="' . $size . '"' : '') . '>';
    }

    /** Taula: només files i cel·les (th o td), sense atributs ni taules a dins. */
    protected static function table(\DOMElement $table): string
    {
        $rows = [];
        $collect = function (\DOMNode $parent) use (&$collect, &$rows): void {
            foreach ($parent->childNodes as $child) {
                if (! $child instanceof \DOMElement) {
                    continue;
                }
                $n = strtolower($child->nodeName);
                if ($n === 'tr') {
                    $rows[] = $child;
                } elseif (in_array($n, ['thead', 'tbody', 'tfoot'], true)) {
                    $collect($child);
                }
            }
        };
        $collect($table);

        self::$tableDepth++;
        try {
            $html = '';
            foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
                $cells = '';
                $count = 0;
                foreach ($row->childNodes as $cell) {
                    if (! $cell instanceof \DOMElement || ! in_array(strtolower($cell->nodeName), ['td', 'th'], true) || $count >= self::MAX_COLS) {
                        continue;
                    }
                    $tag = strtolower($cell->nodeName);
                    $cells .= '<' . $tag . '>' . trim(self::children($cell)) . '</' . $tag . '>';
                    $count++;
                }
                if ($count) {
                    $html .= '<tr>' . $cells . '</tr>';
                }
            }
        } finally {
            self::$tableDepth--;
        }

        return $html === '' ? '' : '<table><tbody>' . $html . '</tbody></table>';
    }
}
