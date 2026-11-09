<?php

namespace Mailing\Editor;

/**
 * Contingut d'un correu del Mailing: estils generals («settings») i una llista
 * de blocs. Tot el que arriba de l'editor NO és de fiar: aquí es reconstrueix
 * amb només els tipus, camps i valors admesos (colors, mides, enllaços i text
 * amb format net). El resultat és el que es desa i el que dibuixa MailRenderer.
 */
class MailDoc
{
    public const MAX_BLOCKS = 150;

    /** Tipus de bloc => nom que veu la persona. */
    public const TYPES = [
        'header' => 'Capçalera',
        'heading' => 'Títol',
        'text' => 'Text',
        'image' => 'Imatge',
        'button' => 'Botó',
        'link' => 'Enllaç',
        'box' => 'Requadre',
        'columns' => 'Columnes',
        'imagetext' => 'Imatge i text',
        'divider' => 'Separador',
        'spacer' => 'Espai',
        'footer' => 'Peu',
        'event' => 'Esdeveniment',
        'post' => 'Article',
    ];

    /**
     * Marca del projecte (nom, color, web, adreça) per als valors per defecte
     * dels blocs nous i dels estils. La posa Brand::apply() abans d'editar.
     *
     * @var array{name: string, color: string, website: string, note: string}
     */
    public static array $brand = ['name' => '', 'color' => '#ac1966', 'website' => '', 'note' => ''];

    /** Tipus de lletra (segurs a tots els programes de correu). */
    public const FONTS = [
        'arial' => ['label' => 'Arial', 'stack' => "Arial, 'Helvetica Neue', Helvetica, sans-serif"],
        'segoe' => ['label' => 'Segoe UI', 'stack' => "'Segoe UI', Tahoma, Arial, sans-serif"],
        'verdana' => ['label' => 'Verdana', 'stack' => 'Verdana, Geneva, Arial, sans-serif'],
        'georgia' => ['label' => 'Georgia', 'stack' => "Georgia, 'Times New Roman', serif"],
    ];

    public const WIDTH = 600;

    /** Estils generals per defecte (el color d'accent és el del projecte: vegeu settingsDefaults()). */
    public const SETTINGS = [
        'bodyBg' => '#ffffff',
        'contentBg' => '#ffffff',
        'font' => 'arial',
        'textColor' => '#000000',
        'textSize' => 16,
        'headingColor' => '#333333',
        'linkColor' => '#ac1966',
        'accent' => '#ac1966',
        'padX' => 20,
    ];

    /** Estils per defecte amb el color del projecte. */
    public static function settingsDefaults(): array
    {
        $c = self::color(self::$brand['color'], self::SETTINGS['accent']);

        return ['linkColor' => $c, 'accent' => $c] + self::SETTINGS;
    }

    /** Valors per defecte de cada tipus de bloc. */
    public static function defaults(string $type): array
    {
        $common = ['pt' => 10, 'pb' => 10, 'bg' => '', 'hideMobile' => false];

        return match ($type) {
            'header' => ['src' => 'brand:logo', 'alt' => self::$brand['name'], 'width' => 220, 'align' => 'center', 'href' => self::$brand['website'],
                'borderTop' => self::$brand['color'], 'borderWidth' => 0, 'pt' => 20, 'pb' => 20] + $common,
            'heading' => ['text' => 'Títol', 'level' => 1, 'size' => 18, 'color' => '#333333', 'align' => 'left', 'pt' => 20, 'pb' => 6] + $common,
            'text' => ['html' => '<p>Escriu aquí el text.</p>', 'size' => 0, 'color' => '', 'align' => 'left'] + $common,
            'image' => ['src' => '', 'alt' => '', 'width' => 0, 'align' => 'center', 'href' => '', 'radius' => 0] + $common,
            'button' => ['text' => 'Més informació', 'href' => '', 'btnBg' => '', 'color' => '#ffffff', 'align' => 'center', 'full' => false, 'mobileFull' => true,
                'radius' => 8, 'size' => 16, 'pt' => 16, 'pb' => 16] + $common,
            'link' => ['text' => 'Més informació >', 'href' => '', 'color' => '', 'bold' => true, 'align' => 'left', 'size' => 0] + $common,
            'box' => ['html' => '<p><strong>Text destacat</strong></p>', 'boxBg' => '#f9eaf2', 'color' => '', 'radius' => 10, 'size' => 0] + $common,
            'columns' => ['count' => 2, 'cols' => [self::col(), self::col()]] + $common,
            'imagetext' => ['side' => 'left'] + self::col() + $common,
            'divider' => ['color' => '#cccccc', 'thickness' => 1, 'widthPct' => 100] + $common,
            'spacer' => ['height' => 24, 'pt' => 0, 'pb' => 0] + $common,
            'footer' => ['src' => 'brand:logo', 'alt' => self::$brand['name'], 'width' => 0, 'name' => self::$brand['name'],
                'links' => self::$brand['website'] !== '' ? [['text' => 'Web', 'href' => self::$brand['website']]] : [],
                'note' => self::$brand['note'], 'lineColor' => '#e5e7eb', 'color' => '#8a909c', 'linkColor' => '', 'pt' => 32, 'pb' => 32] + $common,
            'event' => ['refId' => '', 'title' => 'Nom de l\'esdeveniment', 'when' => '', 'day' => '', 'month' => '', 'location' => '', 'desc' => '',
                'btnText' => 'Més informació >', 'href' => '', 'boxBg' => '#f6f7f9', 'pt' => 12, 'pb' => 12] + $common,
            'post' => ['refId' => '', 'title' => 'Títol de l\'article', 'desc' => 'Resum de l\'article.', 'date' => '', 'src' => '', 'alt' => '', 'layout' => 'card', 'btnText' => 'Llegeix-lo >', 'href' => '', 'pt' => 12, 'pb' => 12] + $common,
            default => $common,
        };
    }

    /** Una columna (també el bloc «Imatge i text»). */
    public static function col(): array
    {
        return ['src' => '', 'alt' => '', 'title' => 'Títol', 'html' => '<p>Text de la columna.</p>', 'linkText' => '', 'href' => ''];
    }

    /** Document nou amb els blocs indicats (tipus o [tipus, camps]). */
    public static function make(array $blocks, array $settings = []): array
    {
        $out = [];
        foreach ($blocks as $b) {
            [$type, $fields] = is_array($b) ? [$b[0], $b[1] ?? []] : [$b, []];
            $out[] = ['type' => $type] + $fields;
        }

        return self::clean(['settings' => $settings, 'blocks' => $out]);
    }

    /**
     * Document net a partir del que envia l'editor.
     *
     * @return array{settings: array<string, mixed>, blocks: array<int, array<string, mixed>>}
     */
    public static function clean(mixed $doc): array
    {
        $doc = is_array($doc) ? $doc : [];
        $s = is_array($doc['settings'] ?? null) ? $doc['settings'] : [];
        $d = self::settingsDefaults();
        $settings = [
            'bodyBg' => self::color($s['bodyBg'] ?? null, $d['bodyBg']),
            'contentBg' => self::color($s['contentBg'] ?? null, $d['contentBg']),
            'font' => isset(self::FONTS[$s['font'] ?? '']) ? $s['font'] : $d['font'],
            'textColor' => self::color($s['textColor'] ?? null, $d['textColor']),
            'textSize' => self::int($s['textSize'] ?? null, 12, 22, $d['textSize']),
            'headingColor' => self::color($s['headingColor'] ?? null, $d['headingColor']),
            'linkColor' => self::color($s['linkColor'] ?? null, $d['linkColor']),
            'accent' => self::color($s['accent'] ?? null, $d['accent']),
            'padX' => self::int($s['padX'] ?? null, 0, 48, $d['padX']),
        ];

        $blocks = [];
        $ids = [];
        foreach (array_slice(is_array($doc['blocks'] ?? null) ? array_values($doc['blocks']) : [], 0, self::MAX_BLOCKS) as $raw) {
            $block = is_array($raw) ? self::block($raw) : null;
            if ($block === null) {
                continue;
            }
            while (isset($ids[$block['id']])) {
                $block['id'] = self::newId();
            }
            $ids[$block['id']] = true;
            $blocks[] = $block;
        }

        return ['settings' => $settings, 'blocks' => $blocks];
    }

    public static function newId(): string
    {
        return 'b' . bin2hex(random_bytes(5));
    }

    /** @return array<string, mixed>|null */
    protected static function block(array $raw): ?array
    {
        $type = (string) ($raw['type'] ?? '');
        if (! isset(self::TYPES[$type])) {
            return null;
        }
        $d = self::defaults($type);
        $id = preg_match('/^[a-z0-9]{1,24}$/i', (string) ($raw['id'] ?? '')) ? (string) $raw['id'] : self::newId();
        $b = ['id' => $id, 'type' => $type,
            'pt' => self::int($raw['pt'] ?? null, 0, 120, $d['pt']),
            'pb' => self::int($raw['pb'] ?? null, 0, 120, $d['pb']),
            'bg' => self::color($raw['bg'] ?? null, ''),
            'hideMobile' => (bool) ($raw['hideMobile'] ?? false),
        ];

        $f = fn (string $k) => $raw[$k] ?? $d[$k] ?? null;

        switch ($type) {
            case 'header':
                $b += ['src' => self::src($f('src')), 'alt' => self::plain($f('alt'), 200), 'width' => self::int($f('width'), 60, 560, 270),
                    'align' => self::align($f('align'), 'center'), 'href' => self::url($f('href')),
                    'borderTop' => self::color($f('borderTop'), ''), 'borderWidth' => self::int($f('borderWidth'), 0, 10, 2)];
                break;
            case 'heading':
                $level = (int) $f('level') === 2 ? 2 : 1;
                $b += ['text' => self::plain($f('text'), 300), 'level' => $level, 'size' => self::int($f('size'), 12, 40, $level === 2 ? 19 : 18),
                    'color' => self::color($f('color'), ''), 'align' => self::align($f('align'), 'left')];
                break;
            case 'text':
                $b += ['html' => self::rich($f('html')), 'size' => self::int($f('size'), 0, 30, 0), 'color' => self::color($f('color'), ''), 'align' => self::align($f('align'), 'left')];
                break;
            case 'image':
                $b += ['src' => self::src($f('src')), 'alt' => self::plain($f('alt'), 200), 'width' => self::int($f('width'), 0, 600, 0),
                    'align' => self::align($f('align'), 'center'), 'href' => self::url($f('href')), 'radius' => self::int($f('radius'), 0, 40, 0)];
                break;
            case 'button':
                $b += ['text' => self::plain($f('text'), 80), 'href' => self::url($f('href')), 'btnBg' => self::color($f('btnBg'), ''),
                    'color' => self::color($f('color'), '#ffffff'), 'align' => self::align($f('align'), 'center'), 'full' => (bool) $f('full'),
                    'mobileFull' => (bool) $f('mobileFull'), 'radius' => self::int($f('radius'), 0, 30, 8), 'size' => self::int($f('size'), 12, 24, 16)];
                break;
            case 'link':
                $b += ['text' => self::plain($f('text'), 160), 'href' => self::url($f('href')), 'color' => self::color($f('color'), ''),
                    'bold' => (bool) $f('bold'), 'align' => self::align($f('align'), 'left'), 'size' => self::int($f('size'), 0, 30, 0)];
                break;
            case 'box':
                $b += ['html' => self::rich($f('html')), 'boxBg' => self::color($f('boxBg'), '#f9eaf2'), 'color' => self::color($f('color'), ''),
                    'radius' => self::int($f('radius'), 0, 30, 10), 'size' => self::int($f('size'), 0, 30, 0)];
                break;
            case 'columns':
                $count = (int) $f('count') === 3 ? 3 : 2;
                $cols = array_values(is_array($raw['cols'] ?? null) ? $raw['cols'] : []);
                $b += ['count' => $count, 'cols' => array_map(fn ($i) => self::column(is_array($cols[$i] ?? null) ? $cols[$i] : []), range(0, $count - 1))];
                break;
            case 'imagetext':
                $b += ['side' => $f('side') === 'right' ? 'right' : 'left'] + self::column($raw);
                break;
            case 'divider':
                $b += ['color' => self::color($f('color'), '#cccccc'), 'thickness' => self::int($f('thickness'), 1, 8, 1), 'widthPct' => self::int($f('widthPct'), 10, 100, 100)];
                break;
            case 'spacer':
                $b += ['height' => self::int($f('height'), 4, 160, 24)];
                break;
            case 'event':
                $b += self::sourceFields($f, ['when' => 160, 'day' => 4, 'month' => 12, 'location' => 300])
                    + ['boxBg' => self::color($f('boxBg'), '#f6f7f9')];
                break;
            case 'post':
                $b += self::sourceFields($f, ['date' => 60, 'alt' => 200])
                    + ['src' => self::src($f('src')), 'layout' => $f('layout') === 'side' ? 'side' : 'card'];
                break;
            case 'footer':
                $links = [];
                foreach (array_slice(is_array($raw['links'] ?? null) ? array_values($raw['links']) : (array) $d['links'], 0, 6) as $l) {
                    $text = self::plain(is_array($l) ? ($l['text'] ?? '') : '', 60);
                    if ($text !== '') {
                        $links[] = ['text' => $text, 'href' => self::url(is_array($l) ? ($l['href'] ?? '') : '')];
                    }
                }
                $b += ['src' => self::src($f('src')), 'alt' => self::plain($f('alt'), 200), 'width' => self::int($f('width'), 0, 400, 200),
                    'name' => self::plain($f('name'), 200), 'links' => $links, 'note' => self::plain($f('note'), 1500, true),
                    'lineColor' => self::color($f('lineColor'), ''), 'color' => self::color($f('color'), '#999999'), 'linkColor' => self::color($f('linkColor'), '')];
                break;
        }

        return $b;
    }

    /**
     * Camps comuns dels blocs que venen d'una font: referència, títol, text,
     * botó i enllaç (+ els textos curts que s'indiquin, amb la llargada màxima).
     */
    protected static function sourceFields(callable $f, array $extra): array
    {
        $out = [
            'refId' => preg_match('/^[0-9]{1,12}$/', (string) $f('refId')) ? (string) $f('refId') : '',
            'title' => self::plain($f('title'), 300),
            'desc' => self::plain($f('desc'), 1500, true),
            'btnText' => self::plain($f('btnText'), 80),
            'href' => self::url($f('href')),
        ];
        foreach ($extra as $k => $max) {
            $out[$k] = self::plain($f($k), $max);
        }

        return $out;
    }

    protected static function column(array $c): array
    {
        $d = self::col();

        return [
            'src' => self::src($c['src'] ?? ''),
            'alt' => self::plain($c['alt'] ?? '', 200),
            'title' => self::plain($c['title'] ?? $d['title'], 200),
            'html' => self::rich($c['html'] ?? ''),
            'linkText' => self::plain($c['linkText'] ?? '', 80),
            'href' => self::url($c['href'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------

    public static function color(mixed $v, string $default): string
    {
        $v = strtolower(trim((string) $v));
        if (preg_match('/^#[0-9a-f]{3}$/', $v)) {
            $v = '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : $default;
    }

    public static function int(mixed $v, int $min, int $max, int $default): int
    {
        if (! is_numeric($v)) {
            return $default;
        }

        return max($min, min($max, (int) round((float) $v)));
    }

    protected static function align(mixed $v, string $default): string
    {
        return in_array($v, ['left', 'center', 'right'], true) ? $v : $default;
    }

    /** Text sense format (els salts de línia només si $lines). */
    public static function plain(mixed $v, int $max, bool $lines = false): string
    {
        $v = (string) (is_scalar($v) ? $v : '');
        $v = str_replace("\r", '', $v);
        $v = $lines ? preg_replace('/[^\P{C}\n]/u', '', $v) : preg_replace('/\p{C}+/u', ' ', $v);
        $v = trim((string) $v);

        return mb_substr($v, 0, $max);
    }

    /** Enllaç acceptat: http(s), correu o telèfon. La resta, buit. */
    public static function url(mixed $v): string
    {
        $v = trim((string) (is_scalar($v) ? $v : ''));
        if ($v === '' || mb_strlen($v) > 1000) {
            return '';
        }
        // Camps personalitzats: {{unsubscribe_url}}, {{web_url}}…
        if (preg_match('/^\{\{\s*[a-z0-9_:]+\s*\}\}$/i', $v)) {
            return $v;
        }
        if (preg_match('/^www\./i', $v)) {
            $v = 'https://' . $v;
        }

        return preg_match('#^(https?://[^\s<>"]+|mailto:[^\s<>"]+|tel:[0-9+ ()-]+)$#i', $v) ? $v : '';
    }

    /** Imatge: pujada a la plataforma (img:codi), el logotip (brand:logo) o una adreça https. */
    public static function src(mixed $v): string
    {
        $v = trim((string) (is_scalar($v) ? $v : ''));
        if ($v === 'brand:logo' || preg_match('/^img:[A-Za-z0-9]{40}$/', $v)) {
            return $v;
        }

        return preg_match('#^https://[^\s<>"\']+$#i', $v) && mb_strlen($v) <= 1000 ? $v : '';
    }

    /** Text amb format: el mateix filtre que l'editor visual de les tasques. */
    protected static function rich(mixed $v): string
    {
        return (string) RichText::clean(is_string($v) ? $v : '');
    }
}
