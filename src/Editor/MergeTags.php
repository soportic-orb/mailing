<?php

declare(strict_types=1);

namespace Mailing\Editor;

/**
 * {{nom}}, {{cognoms}}, {{email}}, {{camp:clau}}, {{unsubscribe_url}}, {{view_url}}…
 * Filled in for each recipient when the email is sent. An unknown tag becomes
 * empty. A default can be given: {{nom|amiga}}.
 */
final class MergeTags
{
    public const BASIC = [
        'nom' => 'Nom',
        'cognoms' => 'Cognoms',
        'nom_complet' => 'Nom i cognoms',
        'email' => 'Correu electrònic',
    ];

    /** @return array<string, string> tag => label, for the editor menu */
    public static function available(array $fields): array
    {
        $out = self::BASIC;
        foreach ($fields as $f) {
            $out['camp:' . $f['key']] = $f['label'];
        }
        return $out;
    }

    /** Values for one contact (HTML-escaped when $html). */
    public static function vars(array $contact, array $extra = []): array
    {
        $fields = is_array($contact['fields'] ?? null) ? $contact['fields'] : (json_decode((string) ($contact['fields'] ?? ''), true) ?: []);
        $vars = [
            'nom' => (string) ($contact['first_name'] ?? ''),
            'cognoms' => (string) ($contact['last_name'] ?? ''),
            'nom_complet' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
            'email' => (string) ($contact['email'] ?? ''),
        ];
        foreach ($fields as $k => $v) {
            $vars['camp:' . $k] = is_scalar($v) ? (string) $v : '';
        }
        return $extra + $vars;
    }

    public static function apply(string $content, array $vars, bool $html = true): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_:]+)\s*(?:\|([^}]*))?\}\}/i', static function ($m) use ($vars, $html) {
            $key = strtolower($m[1]);
            $v = (string) ($vars[$key] ?? '');
            if ($v === '' && isset($m[2])) {
                $v = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return $html ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;
        }, $content);
    }
}
