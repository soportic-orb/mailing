<?php

declare(strict_types=1);

namespace Mailing\Editor;

/**
 * The content of a campaign or template: one MailDoc per language with its
 * subject and preview text.
 *
 *   {"v":1, "primary":"ca", "langs":{"ca":{"subject":"…","preheader":"…","doc":{settings, blocks}}, "es":{…}}}
 *
 * Every save goes through clean() (whitelist) and the HTML is rendered on the
 * server, never trusted from the browser.
 */
final class Document
{
    public const LANGS = ['ca' => 'Català', 'es' => 'Castellano', 'en' => 'English', 'fr' => 'Français'];

    /** Footer text added to every email (with the unsubscribe link), per language. */
    private const TAIL = [
        'ca' => ['why' => 'Reps aquest correu perquè formes part dels contactes de %s.', 'unsub' => 'Dona\'t de baixa', 'view' => 'Veure-ho al navegador'],
        'es' => ['why' => 'Recibes este correo porque formas parte de los contactos de %s.', 'unsub' => 'Darse de baja', 'view' => 'Ver en el navegador'],
        'en' => ['why' => 'You are receiving this email because you are a contact of %s.', 'unsub' => 'Unsubscribe', 'view' => 'View in your browser'],
        'fr' => ['why' => 'Vous recevez ce message car vous faites partie des contacts de %s.', 'unsub' => 'Se désabonner', 'view' => 'Voir dans le navigateur'],
    ];

    public static function blank(string $lang = 'ca', ?array $doc = null, string $subject = '', string $preheader = ''): array
    {
        $lang = isset(self::LANGS[$lang]) ? $lang : 'ca';
        return ['v' => 1, 'primary' => $lang, 'langs' => [$lang => [
            'subject' => $subject,
            'preheader' => $preheader,
            'doc' => MailDoc::clean($doc ?? self::starter()),
        ]]];
    }

    /** A new email: header, heading, text, button and footer. */
    public static function starter(): array
    {
        return MailDoc::make([
            'header',
            ['heading', ['text' => 'Títol del correu', 'size' => 24]],
            'text',
            'button',
            'footer',
        ]);
    }

    public static function clean(mixed $raw, string $fallbackLang = 'ca'): array
    {
        $raw = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);
        // An old single document (settings + blocks) becomes the primary language.
        if (isset($raw['blocks']) && !isset($raw['langs'])) {
            return self::blank($fallbackLang, $raw);
        }
        $langs = [];
        foreach ((array) ($raw['langs'] ?? []) as $code => $l) {
            if (!isset(self::LANGS[$code]) || !is_array($l)) {
                continue;
            }
            $langs[$code] = [
                'subject' => MailDoc::plain($l['subject'] ?? '', 255),
                'preheader' => MailDoc::plain($l['preheader'] ?? '', 255),
                'doc' => MailDoc::clean($l['doc'] ?? []),
            ];
        }
        if (!$langs) {
            return self::blank($fallbackLang);
        }
        $primary = (string) ($raw['primary'] ?? '');
        if (!isset($langs[$primary])) {
            $primary = array_key_first($langs);
        }
        // Primary language first.
        $langs = [$primary => $langs[$primary]] + $langs;

        return ['v' => 1, 'primary' => $primary, 'langs' => $langs];
    }

    public static function decode(?string $json, string $fallbackLang = 'ca'): array
    {
        return self::clean(json_decode((string) $json, true) ?: [], $fallbackLang);
    }

    public static function encode(array $doc): string
    {
        return json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Subject of a language (falls back to the primary). */
    public static function subject(array $doc, ?string $lang = null): string
    {
        return (string) ($doc['langs'][$lang ?? '']['subject'] ?? $doc['langs'][$doc['primary']]['subject'] ?? '');
    }

    /** Language used for a contact: theirs if the email has it, otherwise the primary. */
    public static function langFor(array $doc, ?string $contactLang): string
    {
        return $contactLang && isset($doc['langs'][$contactLang]) ? $contactLang : $doc['primary'];
    }

    /**
     * Renders every language. Merge tags ({{nom}}, {{unsubscribe_url}}…) stay
     * in the HTML: they are filled in for each recipient when sending.
     *
     * @return array<string, string> lang => HTML
     */
    public static function render(array $doc, ?array $project, bool $withTail = true): array
    {
        Brand::apply($project);
        $logo = Brand::logoUrl($project);
        $out = [];
        foreach ($doc['langs'] as $code => $l) {
            $r = new MailRenderer(static fn (string $src): ?string => Images::publicUrl($src, $logo));
            $r->lang = $code;
            if ($withTail) {
                $r->tail = self::tail($l['doc'], $project, $code);
            }
            $out[$code] = $r->html($l['doc'], $l['subject'], $l['preheader']);
        }
        return $out;
    }

    /** Plain-text version (multipart alternative). */
    public static function text(array $doc, string $lang, ?array $project): string
    {
        $l = $doc['langs'][$lang] ?? $doc['langs'][$doc['primary']];
        $r = new MailRenderer(static fn (string $src): ?string => null);
        $t = self::TAIL[$lang] ?? self::TAIL['ca'];
        return $r->text($l['doc'], $l['subject']) . "\n--\n" . sprintf($t['why'], (string) ($project['name'] ?? ''))
            . "\n" . $t['unsub'] . ': {{unsubscribe_url}}' . "\n";
    }

    /** Footer row: why you get this email, unsubscribe and "view in browser" links, postal address. */
    private static function tail(array $mailDoc, ?array $project, string $lang): string
    {
        $s = MailDoc::clean($mailDoc)['settings'];
        $font = MailDoc::FONTS[$s['font']]['stack'];
        $t = self::TAIL[$lang] ?? self::TAIL['ca'];
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        $name = (string) ($project['name'] ?? '');
        $addr = trim(implode(' · ', array_filter([(string) ($project['company'] ?? ''), (string) ($project['postal_address'] ?? '')])));
        $p = 'margin:0;font-family:' . str_replace('"', "'", $font) . ';font-size:11px;line-height:17px;color:#8a909c;text-align:center;mso-line-height-rule:exactly;';
        $a = 'color:#6b7280;text-decoration:underline;';

        return '<tr><td style="padding:18px ' . $s['padX'] . 'px 26px;background-color:' . $s['bodyBg'] . ';">'
            . '<p style="' . $p . '">' . $h(sprintf($t['why'], $name)) . '</p>'
            . '<p style="' . $p . '"><a href="{{unsubscribe_url}}" target="_blank" style="' . $a . '">' . $h($t['unsub']) . '</a>'
            . '&nbsp;&nbsp;·&nbsp;&nbsp;<a href="{{view_url}}" target="_blank" style="' . $a . '">' . $h($t['view']) . '</a></p>'
            . ($addr !== '' ? '<p style="' . $p . '">' . $h($addr) . '</p>' : '')
            . '</td></tr>' . "\n";
    }
}
