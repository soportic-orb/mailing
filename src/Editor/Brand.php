<?php

declare(strict_types=1);

namespace Mailing\Editor;

/** Puts the project's identity (name, colour, website, address, logo) into the editor defaults. */
final class Brand
{
    public static function apply(?array $project): void
    {
        if (!$project) {
            return;
        }
        $note = trim(implode("\n", array_filter([(string) ($project['company'] ?? ''), (string) ($project['postal_address'] ?? '')])));
        MailDoc::$brand = [
            'name' => (string) $project['name'],
            'color' => MailDoc::color($project['color'] ?? '', '#ac1966'),
            'website' => MailDoc::url($project['website'] ?? ''),
            'note' => $note,
        ];
    }

    /** Public URL of the project logo (the "brand:logo" image), or ''. */
    public static function logoUrl(?array $project): string
    {
        $src = (string) ($project['logo_src'] ?? '');
        if (str_starts_with($src, 'img:')) {
            return url('img/' . substr($src, 4));
        }
        return preg_match('#^https://#', $src) ? $src : '';
    }
}
