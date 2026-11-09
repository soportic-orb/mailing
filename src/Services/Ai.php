<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Http;
use Mailing\Core\Settings;
use Mailing\Editor\Document;
use Mailing\Editor\Images;
use Mailing\Editor\MailDoc;
use Mailing\Editor\RichText;

/**
 * AI in the editor, ported from the Tauler (MailingAi): texts with Claude or
 * OpenAI, images with OpenAI (Claude does not make images), and automatic
 * translation of a whole email (subject, preview text and every block),
 * keeping tags and {{merge}} fields.
 */
final class Ai
{
    public const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    public const OPENAI_URL = 'https://api.openai.com/v1';

    public const FORMATS = ['paragraphs' => 'Paràgrafs', 'list' => 'Llista', 'heading' => 'Títol', 'button' => 'Text de botó', 'subject' => 'Assumpte i resum'];
    public const TONES = ['proper' => 'Proper', 'formal' => 'Formal', 'comercial' => 'Comercial', 'urgent' => 'Urgent i clar'];
    public const LENGTHS = ['curt' => 'Curt', 'mitja' => 'Mitjà', 'llarg' => 'Llarg'];
    public const SHAPES = [
        'landscape' => ['label' => 'Horitzontal', 'size' => '1536x1024'],
        'square' => ['label' => 'Quadrada', 'size' => '1024x1024'],
        'portrait' => ['label' => 'Vertical', 'size' => '1024x1536'],
    ];
    public const STYLES = [
        'photo' => ['label' => 'Fotografia', 'prompt' => 'Fotografia realista, llum natural, composició neta.'],
        'illustration' => ['label' => 'Il·lustració', 'prompt' => 'Il·lustració editorial moderna, colors suaus.'],
        'flat' => ['label' => 'Dibuix pla', 'prompt' => 'Il·lustració plana i minimalista, formes simples, fons clar.'],
    ];
    public const MAX_REFS = 4;
    public const REF_MAX_KB = 10240;
    private const REF_MAX_SIDE = 2048;

    private const LANG_NAMES = ['ca' => 'català', 'es' => 'castellà (espanyol)', 'en' => 'anglès', 'fr' => 'francès'];

    public function __construct(private ?array $project = null)
    {
    }

    public function enabled(): bool
    {
        return Settings::get('ai_enabled', '1') !== '0';
    }

    public function claudeReady(): bool
    {
        return Settings::has('anthropic_key');
    }

    public function openaiReady(): bool
    {
        return Settings::has('openai_key');
    }

    /** @return array<string, string> */
    public function textProviders(): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $out = [];
        if ($this->claudeReady()) {
            $out['claude'] = 'Claude (Anthropic)';
        }
        if ($this->openaiReady()) {
            $out['openai'] = 'OpenAI';
        }
        return $out;
    }

    public function imageReady(): bool
    {
        return $this->enabled() && $this->openaiReady();
    }

    public function defaultTextProvider(): ?string
    {
        $p = $this->textProviders();
        $want = Settings::get('ai_default_text');
        return isset($p[$want]) ? $want : (array_key_first($p) ?: null);
    }

    public function claudeModel(): string
    {
        return Settings::get('claude_model', 'claude-opus-5-5');
    }

    public function openaiTextModel(): string
    {
        return Settings::get('openai_text_model', 'gpt-5');
    }

    public function openaiImageModel(): string
    {
        return Settings::get('openai_image_model', 'gpt-image-1');
    }

    // ------------------------------------------------------------------ text

    /** @return array{html: string, text: string, preheader: string, provider: string} */
    public function text(string $provider, string $prompt, array $opts): array
    {
        if (!isset($this->textProviders()[$provider])) {
            throw new \RuntimeException('Aquest servei d\'IA no està configurat. Demana-ho a una persona administradora.');
        }
        $prompt = MailDoc::plain($prompt, 4000, true);
        if ($prompt === '') {
            throw new \RuntimeException('Escriu què vols que escrigui la IA.');
        }
        $format = isset(self::FORMATS[$opts['format'] ?? '']) ? $opts['format'] : 'paragraphs';
        $max = in_array($format, ['heading', 'button', 'subject'], true) ? 600 : 3000;
        $raw = $this->complete($provider, $this->system($format, $opts), $this->userMessage($prompt, $opts), $max);
        logger('IA text', ['provider' => $provider, 'format' => $format], 'ai');
        return $this->shape($raw, $format) + ['provider' => $provider];
    }

    private function system(string $format, array $opts): string
    {
        $tone = self::TONES[$opts['tone'] ?? ''] ?? 'Proper';
        $length = ['curt' => 'Breu: un paràgraf curt o tres punts com a màxim.', 'llarg' => 'Desenvolupat: entre quatre i sis paràgrafs.'][$opts['length'] ?? ''] ?? 'Mitjà: dos o tres paràgrafs.';
        $lang = self::LANG_NAMES[$opts['lang'] ?? 'ca'] ?? 'català';
        $who = $this->project ? 'de ' . $this->project['name'] . ($this->project['website'] ? ' (' . $this->project['website'] . ')' : '') : 'd\'una organització';
        $base = "Redactes correus electrònics {$who} per als seus clients i contactes. "
            . "Escriu en {$lang} correcte, clar i natural. To: {$tone}. "
            . 'No inventis dades concretes que no surtin a la petició o al context (dates, hores, preus, xifres, noms): si en falta alguna, posa-la entre claudàtors, com [data] o [preu]. '
            . 'El correu ja té capçalera, logotip, peu i enllaç de baixa: no hi afegeixis signatura, adreces ni dades de contacte inventades. '
            . 'Pots fer servir camps personalitzats com {{nom}} si la petició ho demana. '
            . 'Respon només amb el contingut demanat, sense explicacions.';
        return $base . ' ' . match ($format) {
            'list' => 'Format: una llista HTML (<ul> o <ol> amb <li>), opcionalment amb un paràgraf <p> d\'introducció. Pots fer servir <strong>. Cap altra etiqueta. ' . $length,
            'heading' => 'Format: un sol títol, en text pla, d\'un màxim de 12 paraules, sense cometes ni punt final.',
            'button' => 'Format: el text d\'un botó, en text pla, de 2 a 5 paraules, començant amb un verb en imperatiu, sense cometes.',
            'subject' => 'Format: dues línies de text pla. Primera línia: l\'assumpte del correu (màxim 10 paraules, sense cometes). Segona línia: un resum d\'una frase (màxim 15 paraules) que es veurà a la safata d\'entrada.',
            default => 'Format: HTML amb paràgrafs <p>. Pots fer servir <strong>, <em>, <ul>/<ol> amb <li> i enllaços <a href="https://…"> si la petició en dona l\'adreça. Cap altra etiqueta. ' . $length,
        };
    }

    private function userMessage(string $prompt, array $opts): string
    {
        $out = '';
        $title = MailDoc::plain($opts['title'] ?? '', 255);
        if ($title !== '') {
            $out .= "Assumpte del correu: {$title}\n\n";
        }
        $context = MailDoc::plain($opts['context'] ?? '', 8000, true);
        if ($context !== '') {
            $out .= "<context>\nAixò és el que ja diu el correu (fes-lo servir per mantenir la coherència; no el repeteixis):\n{$context}\n</context>\n\n";
        }
        $current = MailDoc::plain($opts['current'] ?? '', 8000, true);
        return $out . ($current !== '' ? "<text_actual>\n{$current}\n</text_actual>\n\nReescriu el text actual seguint aquesta petició: {$prompt}" : "Petició: {$prompt}");
    }

    /** @return array{html: string, text: string, preheader: string} */
    private function shape(string $raw, string $format): array
    {
        $raw = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($raw)));
        if ($raw === '') {
            throw new \RuntimeException('La IA no ha retornat cap text. Torna-ho a provar.');
        }
        if (in_array($format, ['heading', 'button', 'subject'], true)) {
            $lines = array_values(array_filter(array_map(static fn ($l) => trim(strip_tags($l), " \t\"'«»“”*#"), preg_split('/\R/', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), static fn ($l) => $l !== ''));
            $first = MailDoc::plain($lines[0] ?? '', $format === 'button' ? 80 : 255);
            $strip = static fn (string $v): string => (string) preg_replace('/^(Assumpte|Resum|Asunto|Subject)\s*:\s*/iu', '', $v);
            return ['html' => '', 'text' => $format === 'subject' ? $strip($first) : $first, 'preheader' => $format === 'subject' ? MailDoc::plain($strip($lines[1] ?? ''), 255) : ''];
        }
        if (!preg_match('/<(p|ul|ol|li|br)\b/i', $raw)) {
            $raw = implode('', array_map(static fn ($p) => '<p>' . nl2br(e(trim($p)), false) . '</p>', preg_split('/\R{2,}/', $raw)));
        }
        $html = (string) RichText::clean($raw);
        if ($html === '') {
            throw new \RuntimeException('La IA no ha retornat cap text aprofitable. Torna-ho a provar.');
        }
        return ['html' => $html, 'text' => RichText::text($html), 'preheader' => ''];
    }

    private function complete(string $provider, string $system, string $prompt, int $max): string
    {
        return $provider === 'claude' ? $this->claude($system, $prompt, $max) : $this->openaiText($system, $prompt, $max);
    }

    private function claude(string $system, string $prompt, int $max): string
    {
        $model = $this->claudeModel();
        $body = ['model' => $model, 'max_tokens' => $max, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $prompt]]];
        $headers = ['x-api-key' => Settings::get('anthropic_key'), 'anthropic-version' => '2023-06-01'];
        $r = Http::request('POST', (string) (env('AI_ANTHROPIC_URL') ?: self::ANTHROPIC_URL), $body, $headers, $max > 4000 ? 300 : 120);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException('Claude no ha respost: ' . (Http::get($r['json'], 'error.message') ?: ($r['error'] ?: 'error ' . $r['status'])) . '.');
        }
        if (Http::get($r['json'], 'stop_reason') === 'refusal') {
            throw new \RuntimeException('Claude no ha volgut generar aquest text. Prova de dir-ho d\'una altra manera.');
        }
        $text = '';
        foreach ((array) Http::get($r['json'], 'content', []) as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= (string) $b['text'];
            }
        }
        return $text;
    }

    private function openaiText(string $system, string $prompt, int $max): string
    {
        $model = $this->openaiTextModel();
        $body = ['model' => $model, 'instructions' => $system, 'input' => $prompt, 'max_output_tokens' => $max + 2000];
        if (preg_match('/^(gpt-5|o\d)/', $model)) {
            $body['reasoning'] = ['effort' => 'low'];
        }
        $r = Http::request('POST', (string) (env('AI_OPENAI_URL') ?: self::OPENAI_URL) . '/responses', $body, ['Authorization' => 'Bearer ' . Settings::get('openai_key')], $max > 4000 ? 300 : 120);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException('OpenAI no ha respost: ' . (Http::get($r['json'], 'error.message') ?: ($r['error'] ?: 'error ' . $r['status'])) . '.');
        }
        $text = (string) (Http::get($r['json'], 'output_text') ?? '');
        if ($text === '') {
            foreach ((array) Http::get($r['json'], 'output', []) as $item) {
                foreach ((array) ($item['content'] ?? []) as $c) {
                    if (($c['type'] ?? '') === 'output_text') {
                        $text .= (string) ($c['text'] ?? '');
                    } elseif (($c['type'] ?? '') === 'refusal') {
                        throw new \RuntimeException('OpenAI no ha volgut generar aquest text. Prova de dir-ho d\'una altra manera.');
                    }
                }
            }
        }
        return $text;
    }

    // ------------------------------------------------------------------ translation

    /**
     * Translates one language of a Document into another: subject, preview
     * text and every text field of every block. The structure, images, links
     * and {{fields}} stay the same.
     *
     * @param array{subject:string, preheader:string, doc:array} $source
     * @return array{subject:string, preheader:string, doc:array}
     */
    public function translate(array $source, string $from, string $to, ?string $provider = null): array
    {
        $provider = $provider && isset($this->textProviders()[$provider]) ? $provider : $this->defaultTextProvider();
        if (!$provider) {
            throw new \RuntimeException('Per traduir cal configurar Claude o OpenAI (Configuració › IA).');
        }
        if (!isset(Document::LANGS[$from], Document::LANGS[$to]) || $from === $to) {
            throw new \RuntimeException('Idiomes no vàlids.');
        }
        $doc = MailDoc::clean($source['doc'] ?? []);
        // Collect the translatable strings with a path, send them as JSON, put them back.
        $strings = ['subject' => (string) ($source['subject'] ?? ''), 'preheader' => (string) ($source['preheader'] ?? '')];
        $walk = static function (array $node, string $path) use (&$walk, &$strings): void {
            foreach ($node as $k => $v) {
                $p = $path === '' ? (string) $k : $path . '.' . $k;
                if (is_array($v)) {
                    $walk($v, $p);
                } elseif (is_string($v) && $v !== '' && in_array((string) $k, ['text', 'html', 'title', 'desc', 'btnText', 'linkText', 'alt', 'name', 'note', 'when', 'location', 'date'], true)) {
                    $strings[$p] = $v;
                }
            }
        };
        $walk(['blocks' => $doc['blocks']], '');
        $strings = array_filter($strings, static fn ($v) => trim(strip_tags($v)) !== '');
        if (!$strings) {
            return ['subject' => '', 'preheader' => '', 'doc' => $doc];
        }
        $system = 'Ets traductor professional de correus electrònics de màrqueting i comunicació. Tradueix del ' . self::LANG_NAMES[$from] . ' al ' . self::LANG_NAMES[$to] . ' amb naturalitat (no literal), mantenint el to. '
            . 'Rebràs un objecte JSON: tradueix NOMÉS els valors, mai les claus. Conserva exactament les etiquetes HTML i els seus atributs, els camps entre dobles claus com {{nom}} o {{unsubscribe_url}}, les adreces web i de correu, i els noms propis i de marques. '
            . 'Respon NOMÉS amb l\'objecte JSON traduït, amb les mateixes claus, sense cap text abans ni després.';
        $raw = $this->complete($provider, $system, json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 8000);
        $raw = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($raw)));
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        $out = $start !== false && $end !== false ? json_decode(substr($raw, $start, $end - $start + 1), true) : null;
        if (!is_array($out)) {
            throw new \RuntimeException('La traducció no ha arribat completa. Torna-ho a provar.');
        }
        $tree = ['blocks' => $doc['blocks']];
        foreach ($strings as $path => $orig) {
            if (!isset($out[$path]) || !is_string($out[$path]) || in_array($path, ['subject', 'preheader'], true)) {
                continue;
            }
            $ref = &$tree;
            foreach (explode('.', $path) as $part) {
                $ref = &$ref[ctype_digit($part) ? (int) $part : $part];
            }
            $ref = $out[$path];
            unset($ref);
        }
        logger('IA traducció', ['provider' => $provider, 'from' => $from, 'to' => $to, 'strings' => count($strings)], 'ai');
        return [
            'subject' => MailDoc::plain($out['subject'] ?? '', 255),
            'preheader' => MailDoc::plain($out['preheader'] ?? '', 255),
            'doc' => MailDoc::clean(['settings' => $doc['settings'], 'blocks' => $tree['blocks']]),
        ];
    }

    // ------------------------------------------------------------------ images

    /** @return array{token: string, width: int, height: int, mime: string} */
    public function image(string $prompt, string $shape, string $style, bool $noText, ?int $userId, array $refs = [], bool $keepDetails = false): array
    {
        if (!$this->imageReady()) {
            throw new \RuntimeException('Per crear imatges cal la clau d\'OpenAI (Claude no en genera). Demana-ho a una persona administradora.');
        }
        $prompt = MailDoc::plain($prompt, 3000, true);
        if ($prompt === '') {
            throw new \RuntimeException('Descriu la imatge que vols.');
        }
        $refs = array_slice($refs, 0, self::MAX_REFS);
        $shapeCfg = self::SHAPES[$shape] ?? self::SHAPES['landscape'];
        $full = $prompt . "\n\n" . (self::STYLES[$style]['prompt'] ?? self::STYLES['photo']['prompt'])
            . ' Imatge per a un correu electrònic' . ($this->project ? ' de ' . $this->project['name'] : '') . ': professional i respectuosa.'
            . ($refs ? ' Fes servir les imatges adjuntes com a referència, tal com indica la descripció.' : '')
            . ($noText ? ' Sense cap text, lletra, logotip ni marca a la imatge.' : '');
        $model = $this->openaiImageModel();
        $auth = ['Authorization' => 'Bearer ' . Settings::get('openai_key')];
        if ($refs) {
            if (!str_starts_with($model, 'gpt-image')) {
                throw new \RuntimeException('Per fer servir imatges de referència cal un model «gpt-image» (ara és «' . $model . '»).');
            }
            $fields = ['model' => $model, 'prompt' => $full, 'n' => '1', 'size' => $shapeCfg['size'], 'quality' => 'medium', 'output_format' => 'jpeg'];
            if ($keepDetails) {
                $fields['input_fidelity'] = 'high';
            }
            foreach ($refs as $i => $ref) {
                $fields['image[' . $i . ']'] = new \CURLStringFile($ref['data'], $ref['name'], $ref['mime']);
            }
            $r = Http::request('POST', (string) (env('AI_OPENAI_URL') ?: self::OPENAI_URL) . '/images/edits', $fields, $auth, 240);
        } else {
            $body = ['model' => $model, 'prompt' => $full, 'n' => 1];
            $body += str_starts_with($model, 'gpt-image')
                ? ['size' => $shapeCfg['size'], 'quality' => 'medium', 'output_format' => 'jpeg']
                : ['size' => ['landscape' => '1792x1024', 'portrait' => '1024x1792'][$shape] ?? '1024x1024', 'response_format' => 'b64_json'];
            $r = Http::request('POST', (string) (env('AI_OPENAI_URL') ?: self::OPENAI_URL) . '/images/generations', $body, $auth, 240);
        }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $msg = (string) (Http::get($r['json'], 'error.message') ?: ($r['error'] ?: 'error ' . $r['status']));
            throw new \RuntimeException(str_contains(strtolower($msg), 'safety') || Http::get($r['json'], 'error.code') === 'moderation_blocked'
                ? 'OpenAI no ha volgut crear aquesta imatge. Prova de descriure-la d\'una altra manera.'
                : 'OpenAI no ha creat la imatge: ' . $msg . '.');
        }
        $b64 = (string) (Http::get($r['json'], 'data.0.b64_json') ?? '');
        $bytes = $b64 !== '' ? base64_decode($b64, true) : false;
        if (!$bytes) {
            throw new \RuntimeException('OpenAI no ha retornat cap imatge. Torna-ho a provar.');
        }
        logger('IA imatge', ['model' => $model, 'refs' => count($refs)], 'ai');
        return Images::storeBytes($bytes, 'imatge-ia.jpg', $this->project ? (int) $this->project['id'] : null, $userId);
    }

    /** Checks and prepares a reference image (JPG/PNG/WebP/GIF, max 2048 px). */
    public function prepareRef(string $data, string $name): array
    {
        if ($data === '' || strlen($data) > self::REF_MAX_KB * 1024) {
            throw new \RuntimeException('«' . $name . '» és massa gran (màxim ' . intdiv(self::REF_MAX_KB, 1024) . ' MB).');
        }
        $info = @getimagesizefromstring($data);
        $types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];
        if (!$info || !isset($types[$info[2]])) {
            throw new \RuntimeException('«' . $name . '» no és una imatge JPG, PNG, WebP o GIF.');
        }
        [$w, $h] = $info;
        $mime = $types[$info[2]];
        $base = preg_replace('/[^a-z0-9]+/i', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'referencia';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? 'png';
        if (($mime === 'image/gif' || max($w, $h) > self::REF_MAX_SIDE) && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($data);
            if (!$src) {
                throw new \RuntimeException('No s\'ha pogut llegir «' . $name . '».');
            }
            $k = min(1, self::REF_MAX_SIDE / max($w, $h));
            $nw = max(1, (int) round($w * $k));
            $nh = max(1, (int) round($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagepng($dst, null, 6);
            $data = (string) ob_get_clean();
            [$mime, $ext] = ['image/png', 'png'];
        } elseif ($mime === 'image/gif') {
            throw new \RuntimeException('Les GIF no es poden fer servir de referència en aquest servidor: desa-la com a PNG o JPG.');
        }
        return ['data' => $data, 'name' => strtolower($base) . '.' . $ext, 'mime' => $mime];
    }

    /** @return array<string, array{ok: bool, message: string}> */
    public function test(): array
    {
        $out = [];
        foreach (['claude', 'openai'] as $p) {
            if (!isset($this->textProviders()[$p])) {
                continue;
            }
            try {
                $r = $this->text($p, 'Escriu només la paraula «Correcte».', ['format' => 'heading']);
                $out[$p] = ['ok' => true, 'message' => ($p === 'claude' ? 'Claude (' . $this->claudeModel() . ')' : 'OpenAI (' . $this->openaiTextModel() . ')') . ' respon: «' . $r['text'] . '».'];
            } catch (\Throwable $e) {
                $out[$p] = ['ok' => false, 'message' => $e->getMessage()];
            }
        }
        return $out;
    }
}
