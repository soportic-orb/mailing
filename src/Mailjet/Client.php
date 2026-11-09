<?php

declare(strict_types=1);

namespace Mailing\Mailjet;

use Mailing\Core\Crypto;
use Mailing\Core\Http;
use Mailing\Core\Settings;

/**
 * Mailjet API: Send API v3.1 (batches of up to 50 messages), senders and
 * domains (v3 REST) and event webhooks. Credentials: the project's own keys
 * if it has them, otherwise the platform's (Settings).
 */
final class Client
{
    public const BASE = 'https://api.mailjet.com';
    public const BATCH = 50;
    public const EVENTS = ['sent', 'open', 'click', 'bounce', 'spam', 'blocked', 'unsub'];

    public function __construct(private string $key, private string $secret)
    {
    }

    public static function forProject(?array $project): self
    {
        $key = $project ? (Crypto::decrypt($project['mailjet_key'] ?? null) ?? '') : '';
        $secret = $project ? (Crypto::decrypt($project['mailjet_secret'] ?? null) ?? '') : '';
        if ($key === '' || $secret === '') {
            $key = Settings::get('mailjet_key');
            $secret = Settings::get('mailjet_secret');
        }
        return new self($key, $secret);
    }

    public function configured(): bool
    {
        return $this->key !== '' && $this->secret !== '';
    }

    /** @return array{status:int, json:mixed, body:string, error:string} */
    private function call(string $method, string $path, mixed $body = null, int $timeout = 30): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Mailjet no està configurat: posa les claus API a Configuració.');
        }
        $r = Http::request($method, (string) (env('MAILJET_BASE') ?: self::BASE) . $path, $body, [], $timeout, $this->key . ':' . $this->secret);
        if ($r['status'] === 0) {
            throw new \RuntimeException('No es pot connectar amb Mailjet: ' . ($r['error'] ?: 'sense resposta') . '.');
        }
        if ($r['status'] === 401) {
            throw new \RuntimeException('Mailjet ha rebutjat les claus API (401). Revisa-les a Configuració.');
        }
        return $r;
    }

    /**
     * Sends up to 50 messages. Each message is the v3.1 format
     * (From, To, Subject, HTMLPart, TextPart, CustomID, Headers…).
     *
     * @return array<int, array{ok: bool, id: ?int, error: ?string}> same order as $messages
     */
    public function send(array $messages, bool $sandbox = false): array
    {
        $r = $this->call('POST', '/v3.1/send', ['SandboxMode' => $sandbox, 'Messages' => array_values($messages)], 60);
        $results = (array) Http::get($r['json'], 'Messages', []);
        if ($r['status'] >= 500 || (!$results && $r['status'] >= 400)) {
            $msg = (string) (Http::get($r['json'], 'ErrorMessage') ?: Http::get($r['json'], 'Messages.0.Errors.0.ErrorMessage') ?: ('error ' . $r['status']));
            throw new \RuntimeException('Mailjet: ' . $msg);
        }
        $out = [];
        foreach (array_values($messages) as $i => $_) {
            $m = $results[$i] ?? [];
            if (($m['Status'] ?? '') === 'success') {
                $out[] = ['ok' => true, 'id' => isset($m['To'][0]['MessageID']) ? (int) $m['To'][0]['MessageID'] : null, 'error' => null];
            } else {
                $err = $m['Errors'][0] ?? [];
                $out[] = ['ok' => false, 'id' => null, 'error' => trim(($err['ErrorMessage'] ?? 'Error desconegut') . (isset($err['ErrorRelatedTo']) ? ' (' . implode(', ', (array) $err['ErrorRelatedTo']) . ')' : ''))];
            }
        }
        return $out;
    }

    /** @return array<int, array{id:int, email:string, name:string, status:string}> */
    public function senders(): array
    {
        $r = $this->call('GET', '/v3/REST/sender?Limit=1000');
        if ($r['status'] >= 400) {
            throw new \RuntimeException('Mailjet: ' . (Http::get($r['json'], 'ErrorMessage') ?: 'error ' . $r['status']));
        }
        $out = [];
        foreach ((array) Http::get($r['json'], 'Data', []) as $s) {
            $out[] = ['id' => (int) ($s['ID'] ?? 0), 'email' => strtolower((string) ($s['Email'] ?? '')), 'name' => (string) ($s['Name'] ?? ''), 'status' => (string) ($s['Status'] ?? '')];
        }
        return $out;
    }

    /** Registers a sender address in Mailjet (Mailjet emails a validation link). */
    public function createSender(string $email, string $name): array
    {
        $r = $this->call('POST', '/v3/REST/sender', ['Email' => $email, 'Name' => $name]);
        if ($r['status'] >= 400 && $r['status'] !== 400) {
            throw new \RuntimeException('Mailjet: ' . (Http::get($r['json'], 'ErrorMessage') ?: 'error ' . $r['status']));
        }
        $id = (int) Http::get($r['json'], 'Data.0.ID', 0);
        if ($id) {
            $this->call('POST', '/v3/REST/sender/' . $id . '/validate');
        }
        return ['id' => $id ?: null, 'message' => (string) Http::get($r['json'], 'ErrorMessage', '')];
    }

    /** Domains and their SPF / DKIM status. @return array<int, array{domain:string, spf:string, dkim:string}> */
    public function domains(): array
    {
        $r = $this->call('GET', '/v3/REST/dns?Limit=1000');
        $out = [];
        foreach ((array) Http::get($r['json'], 'Data', []) as $d) {
            $out[] = ['domain' => (string) ($d['Domain'] ?? ''), 'spf' => (string) ($d['SPFStatus'] ?? ''), 'dkim' => (string) ($d['DKIMStatus'] ?? '')];
        }
        return $out;
    }

    /** Points every event type of this Mailjet account at $url. @return array<string, string> event => result */
    public function setupWebhooks(string $url): array
    {
        $existing = [];
        $r = $this->call('GET', '/v3/REST/eventcallbackurl?Limit=100');
        foreach ((array) Http::get($r['json'], 'Data', []) as $cb) {
            $existing[(string) ($cb['EventType'] ?? '')] = (int) ($cb['ID'] ?? 0);
        }
        $out = [];
        foreach (self::EVENTS as $ev) {
            $body = ['EventType' => $ev, 'Url' => $url, 'Version' => 2, 'IsBackup' => false, 'Status' => 'alive'];
            $res = isset($existing[$ev])
                ? $this->call('PUT', '/v3/REST/eventcallbackurl/' . $existing[$ev], $body)
                : $this->call('POST', '/v3/REST/eventcallbackurl', $body);
            $out[$ev] = $res['status'] < 300 ? 'ok' : (string) (Http::get($res['json'], 'ErrorMessage') ?: 'error ' . $res['status']);
        }
        return $out;
    }

    /** Quick credential check: returns the account's API key name / ok. */
    public function test(): string
    {
        $r = $this->call('GET', '/v3/REST/apikey?Limit=1');
        if ($r['status'] >= 400) {
            throw new \RuntimeException('Mailjet: ' . (Http::get($r['json'], 'ErrorMessage') ?: 'error ' . $r['status']));
        }
        return 'Connexió correcta amb Mailjet.';
    }
}
