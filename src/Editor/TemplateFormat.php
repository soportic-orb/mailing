<?php

declare(strict_types=1);

namespace Mailing\Editor;

/**
 * Template exchange format: a JSON file that Claude (or anyone) can write and
 * that the Templates screen imports and exports.
 *
 *   {"format": "soportic-mailing-template", "version": 1, "name": "…",
 *    "primary": "ca", "langs": {"ca": {"subject": "…", "preheader": "…",
 *      "doc": {"settings": {…}, "blocks": [{"type": "heading", …}, …]}}}}
 *
 * The specification (spec()) is generated from MailDoc, so it always lists
 * exactly the block types and fields the editor understands.
 */
final class TemplateFormat
{
    public const FORMAT = 'soportic-mailing-template';
    public const VERSION = 1;

    /** Fields of each block, explained (the defaults come from MailDoc). */
    private const FIELD_HELP = [
        'pt' => 'espai a dalt, px (0-120)', 'pb' => 'espai a baix, px (0-120)', 'bg' => 'fons de la franja, "#rrggbb" o "" (cap)',
        'hideMobile' => 'amaga el bloc al mòbil (true/false)', 'src' => 'imatge: adreça https://… o "brand:logo" (logotip del projecte)',
        'alt' => 'text alternatiu de la imatge', 'width' => 'amplada de la imatge en px (0 = tota l\'amplada)', 'align' => '"left" | "center" | "right"',
        'href' => 'enllaç: https://…, mailto:…, tel:… o un camp com {{unsubscribe_url}}', 'borderTop' => 'color de la línia superior o ""',
        'borderWidth' => 'gruix de la línia superior, px', 'text' => 'text pla', 'level' => '1 = títol, 2 = subtítol', 'size' => 'mida del text en px (0 = la general)',
        'color' => 'color del text "#rrggbb" o "" (el general)', 'html' => 'text amb format: només <p>, <br>, <strong>, <em>, <u>, <s>, <a href>, <ul>, <ol>, <li>, <h3>, <h4>, <blockquote>',
        'btnBg' => 'color del botó o "" (el d\'accent)', 'full' => 'botó de tota l\'amplada (true/false)', 'mobileFull' => 'al mòbil, tota l\'amplada (true/false)',
        'radius' => 'cantonades arrodonides, px', 'bold' => 'negreta (true/false)', 'boxBg' => 'color de fons del requadre',
        'count' => 'nombre de columnes: 2 o 3', 'cols' => 'llista de columnes: {src, alt, title, html, linkText, href}', 'side' => 'imatge a "left" o "right"',
        'title' => 'títol', 'linkText' => 'text de l\'enllaç (opcional)', 'thickness' => 'gruix de la línia, px', 'widthPct' => 'amplada de la línia, % (10-100)',
        'height' => 'alçada, px (4-160)', 'name' => 'nom de l\'organització', 'links' => 'fins a 6 enllaços {text, href}', 'note' => 'text petit legal (salts de línia permesos)',
        'lineColor' => 'color de la línia superior o ""', 'linkColor' => 'color dels enllaços o "" (el general)', 'refId' => 'deixa-ho buit ""',
        'when' => 'quan (text)', 'day' => 'dia, p. ex. "14" (requadre de color)', 'month' => 'mes curt, p. ex. "nov."', 'location' => 'on (text)',
        'desc' => 'descripció (text pla, salts de línia permesos)', 'btnText' => 'text de l\'enllaç', 'date' => 'data (text)', 'layout' => 'imatge "card" (a dalt) o "side" (al costat)',
    ];

    private const SETTINGS_HELP = [
        'bodyBg' => 'fons de fora del correu', 'contentBg' => 'fons del correu', 'font' => 'tipus de lletra: "arial" | "segoe" | "verdana" | "georgia"',
        'textColor' => 'color del text', 'textSize' => 'mida del text, px (12-22)', 'headingColor' => 'color dels subtítols i títols de columna',
        'linkColor' => 'color dels enllaços', 'accent' => 'color dels botons', 'padX' => 'marges laterals, px (0-48)',
    ];

    /** Export: the template as a portable JSON string (uploaded images become absolute URLs). */
    public static function export(string $name, array $doc): string
    {
        $doc = Document::clean($doc);
        foreach ($doc['langs'] as &$l) {
            array_walk_recursive($l['doc'], static function (&$v, $k): void {
                if ($k === 'src' && is_string($v) && str_starts_with($v, 'img:')) {
                    $v = url('img/' . substr($v, 4));
                }
            });
        }
        unset($l);
        return json_encode(['format' => self::FORMAT, 'version' => self::VERSION, 'name' => $name, 'exported_at' => gmdate('c'),
            'primary' => $doc['primary'], 'langs' => $doc['langs']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Import: accepts the full format, a bare {primary, langs} document, or a
     * single {settings, blocks}. Tolerates text around the JSON (e.g. Claude's
     * reply with ```json fences).
     *
     * @return array{name: string, doc: array, warnings: array<int, string>}
     */
    public static function import(string $raw, string $fallbackLang = 'ca'): array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m)) {
            $raw = $m[1];
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($raw, $start, $end - $start + 1), true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException('No és un JSON vàlid' . (json_last_error() ? ' (' . json_last_error_msg() . ')' : '') . '. Comprova que el fitxer sigui el que ha generat Claude o una exportació de plantilla.');
        }
        if (isset($data['format']) && $data['format'] !== self::FORMAT) {
            throw new \RuntimeException('Aquest fitxer és d\'un altre format («' . mb_substr((string) $data['format'], 0, 60) . '»).');
        }
        $source = isset($data['langs']) ? $data : (isset($data['blocks']) ? ['primary' => $fallbackLang, 'langs' => [$fallbackLang => ['subject' => (string) ($data['subject'] ?? ''), 'preheader' => (string) ($data['preheader'] ?? ''), 'doc' => $data]]] : null);
        if (!$source || !is_array($source['langs'] ?? null)) {
            throw new \RuntimeException('Falta el contingut: s\'esperava «langs» (o «blocks»).');
        }
        $warnings = [];
        foreach ((array) $source['langs'] as $code => $l) {
            if (!isset(Document::LANGS[$code])) {
                $warnings[] = 'Idioma desconegut «' . $code . '»: no s\'ha importat (només ' . implode(', ', array_keys(Document::LANGS)) . ').';
                continue;
            }
            $blocks = is_array($l['doc']['blocks'] ?? null) ? $l['doc']['blocks'] : [];
            foreach ($blocks as $i => $b) {
                $type = is_array($b) ? (string) ($b['type'] ?? '') : '';
                if (!isset(MailDoc::TYPES[$type])) {
                    $warnings[] = 'Bloc ' . ($i + 1) . ' (' . $code . '): tipus desconegut «' . $type . '», s\'ha descartat.';
                }
                foreach (['src'] as $k) {
                    $v = is_array($b) ? (string) ($b[$k] ?? '') : '';
                    if ($v !== '' && MailDoc::src($v) === '') {
                        $warnings[] = 'Bloc ' . ($i + 1) . ' (' . $code . '): la imatge «' . mb_strimwidth($v, 0, 60, '…') . '» no és una adreça https://, s\'ha tret.';
                    }
                }
            }
        }
        $doc = Document::clean($source, $fallbackLang);
        $total = array_sum(array_map(static fn ($l) => count($l['doc']['blocks']), $doc['langs']));
        if ($total === 0) {
            throw new \RuntimeException('La plantilla no té cap bloc vàlid.');
        }
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 190);
        return ['name' => $name, 'doc' => $doc, 'warnings' => array_slice($warnings, 0, 20)];
    }

    /** Markdown specification to give Claude so it can design a template. */
    public static function spec(): string
    {
        $out = "# Format de plantilla de la plataforma de Mailing Soportic\n\n"
            . "Dissenya un correu electrònic i retorna'l com **un sol objecte JSON** (sense text abans ni després) amb aquesta estructura. "
            . "La plataforma el converteix en un correu HTML compatible amb tots els programes (Outlook inclòs), d'una amplada de 600 px que s'adapta al mòbil.\n\n"
            . "```json\n" . json_encode([
                'format' => self::FORMAT, 'version' => self::VERSION, 'name' => 'Nom de la plantilla', 'primary' => 'ca',
                'langs' => ['ca' => ['subject' => 'Assumpte del correu', 'preheader' => 'Resum que es veu a la safata d\'entrada', 'doc' => ['settings' => ['…' => '…'], 'blocks' => [['type' => 'header', '…' => '…'], ['type' => 'heading', '…' => '…']]]]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n\n"
            . "## Regles\n\n"
            . "- `langs`: un objecte per idioma (`ca`, `es`, `en`, `fr`). `primary` és l'idioma principal. Pots fer-ne un de sol.\n"
            . "- Els blocs es dibuixen en ordre, de dalt a baix. Màxim " . MailDoc::MAX_BLOCKS . " blocs. Cada bloc té `type` i els seus camps; els que no posis prenen el valor per defecte.\n"
            . "- Colors sempre en format `\"#rrggbb\"`. Mides en píxels (números).\n"
            . "- Imatges: només adreces `https://` públiques (o `\"brand:logo\"` per al logotip del projecte). Sempre amb `alt`.\n"
            . "- Camps del contacte dins dels textos: `{{nom}}`, `{{cognoms}}`, `{{nom_complet}}`, `{{email}}`, `{{camp:clau}}`; amb valor per defecte: `{{nom|amiga}}`.\n"
            . "- No cal afegir l'enllaç de baixa ni l'adreça postal: la plataforma afegeix sola un peu legal a tots els correus.\n"
            . "- Recomanat: `header` al principi i `footer` al final; botons amb `href`; textos curts i escanejables.\n\n"
            . "## Estils generals (`settings`)\n\n| Camp | Per defecte | Descripció |\n|---|---|---|\n";
        foreach (MailDoc::SETTINGS as $k => $v) {
            $out .= '| `' . $k . '` | `' . json_encode($v, JSON_UNESCAPED_UNICODE) . '` | ' . (self::SETTINGS_HELP[$k] ?? '') . " |\n";
        }
        $out .= "\n## Tipus de bloc\n";
        foreach (MailDoc::TYPES as $type => $label) {
            $d = MailDoc::defaults($type);
            $out .= "\n### `" . $type . '` — ' . $label . "\n\n```json\n" . json_encode(['type' => $type] + $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
            $own = array_diff(array_keys($d), ['pt', 'pb', 'bg', 'hideMobile']);
            foreach ($own as $k) {
                $out .= '- `' . $k . '`: ' . (self::FIELD_HELP[$k] ?? '') . "\n";
            }
        }
        $out .= "\nTots els blocs admeten també `pt`, `pb` (espais, px), `bg` (fons de la franja) i `hideMobile`.\n\n"
            . "## Exemple complet\n\n```json\n" . self::example() . "\n```\n";
        return $out;
    }

    private static function example(): string
    {
        return json_encode([
            'format' => self::FORMAT, 'version' => self::VERSION, 'name' => 'Butlletí mensual', 'primary' => 'ca',
            'langs' => ['ca' => ['subject' => 'Novetats de {{nom|la comunitat}}: el que ha passat aquest mes', 'preheader' => 'Tres notícies, un esdeveniment i una oferta per a tu',
                'doc' => [
                    'settings' => ['bodyBg' => '#f4f5f7', 'contentBg' => '#ffffff', 'font' => 'arial', 'textColor' => '#1f2430', 'textSize' => 16, 'headingColor' => '#0b5cab', 'linkColor' => '#0b5cab', 'accent' => '#0b5cab', 'padX' => 24],
                    'blocks' => [
                        ['type' => 'header', 'src' => 'brand:logo', 'alt' => 'Logotip', 'width' => 180, 'align' => 'center'],
                        ['type' => 'image', 'src' => 'https://images.example.com/portada.jpg', 'alt' => 'Equip treballant', 'radius' => 8],
                        ['type' => 'heading', 'text' => 'Hola {{nom|amiga}}!', 'level' => 1, 'size' => 26, 'align' => 'left'],
                        ['type' => 'text', 'html' => '<p>Aquest mes hem estrenat <strong>tres novetats</strong> que et volem explicar.</p><ul><li>Nou servei de formació</li><li>Horaris ampliats</li><li>App renovada</li></ul>'],
                        ['type' => 'button', 'text' => 'Descobreix-les', 'href' => 'https://example.com/novetats', 'align' => 'center'],
                        ['type' => 'divider', 'color' => '#e5e7eb'],
                        ['type' => 'columns', 'count' => 2, 'cols' => [
                            ['src' => 'https://images.example.com/a.jpg', 'alt' => 'Formació', 'title' => 'Formació', 'html' => '<p>Cursos en línia cada dijous.</p>', 'linkText' => 'Inscriu-t\'hi >', 'href' => 'https://example.com/formacio'],
                            ['src' => 'https://images.example.com/b.jpg', 'alt' => 'App', 'title' => 'Nova app', 'html' => '<p>Més ràpida i més fàcil.</p>', 'linkText' => 'Descarrega-la >', 'href' => 'https://example.com/app'],
                        ]],
                        ['type' => 'event', 'title' => 'Jornada de portes obertes', 'day' => '14', 'month' => 'des.', 'when' => 'Dissabte 14 de desembre, 10-14 h', 'location' => 'Carrer Major, 1 · Barcelona', 'btnText' => 'Apunta-t\'hi >', 'href' => 'https://example.com/jornada'],
                        ['type' => 'box', 'html' => '<p><strong>Oferta per a contactes:</strong> un 10 % de descompte fins a final de mes.</p>', 'boxBg' => '#eef4ff'],
                        ['type' => 'footer', 'src' => 'brand:logo', 'width' => 0, 'name' => 'Organització', 'links' => [['text' => 'Web', 'href' => 'https://example.com'], ['text' => 'Contacte', 'href' => 'mailto:info@example.com']]],
                    ],
                ]]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
