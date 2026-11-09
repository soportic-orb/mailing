<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Settings;
use Mailing\Mailjet\Client;
use Mailing\Services\Activity;
use Mailing\Services\Ai;
use Mailing\Services\Cron;

final class SettingsController extends Controller
{
    public static function webhookUrl(): string
    {
        if (!Settings::has('webhook_key')) {
            Settings::set('webhook_key', random_token(32));
        }
        return url('webhook/mailjet?key=' . Settings::get('webhook_key'));
    }

    public static function cronUrl(): string
    {
        if (!Settings::has('cron_key')) {
            Settings::set('cron_key', random_token(32));
        }
        return url('cron/' . Settings::get('cron_key'));
    }

    public function index(): never
    {
        $this->view('settings/index', [
            'title' => 'Configuració',
            'webhook' => self::webhookUrl(),
            'cronUrl' => self::cronUrl(),
            'cronLine' => Cron::line(),
            'cronAge' => Cron::age(),
            'ai' => new Ai(),
        ]);
    }

    public function save(): never
    {
        $section = str_input('section', 20);
        $secret = static function (string $field, string $key): void {
            $v = trim((string) input($field, ''));
            if ($v === '-') {
                Settings::set($key, '', true);
            } elseif ($v !== '') {
                Settings::set($key, $v, true);
            }
        };
        if ($section === 'mailjet') {
            $secret('mailjet_key', 'mailjet_key');
            $secret('mailjet_secret', 'mailjet_secret');
            Settings::set('send_rate', (string) max(50, min(10000, int_input('send_rate', 600))));
        } elseif ($section === 'ai') {
            Settings::set('ai_enabled', input('ai_enabled') === '1' ? '1' : '0');
            $secret('anthropic_key', 'anthropic_key');
            $secret('openai_key', 'openai_key');
            Settings::set('ai_default_text', in_array(input('ai_default_text'), ['claude', 'openai'], true) ? (string) input('ai_default_text') : 'claude');
            Settings::set('claude_model', str_input('claude_model', 80));
            Settings::set('openai_text_model', str_input('openai_text_model', 80));
            Settings::set('openai_image_model', str_input('openai_image_model', 80));
        } elseif ($section === 'update') {
            Settings::set('update_repo', preg_match('#^[\w.-]+/[\w.-]+$#', str_input('update_repo', 120)) ? str_input('update_repo', 120) : 'soportic-orb/mailing');
            Settings::set('update_branch', str_input('update_branch', 60) ?: 'main');
            $secret('update_token', 'update_token');
        }
        Activity::log('settings.saved', $section);
        $this->done('Configuració desada.', '/configuracio' . ($section ? '#' . $section : ''));
    }

    public function testMailjet(): never
    {
        try {
            $client = Client::forProject(null);
            $msg = $client->test();
            $n = count($client->senders());
            $this->done($msg . ' Remitents al compte: ' . $n . '.', '/configuracio#mailjet');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/configuracio#mailjet');
        }
    }

    public function webhooks(): never
    {
        try {
            $r = Client::forProject(null)->setupWebhooks(self::webhookUrl());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/configuracio#mailjet');
        }
        $bad = array_filter($r, static fn ($v) => $v !== 'ok');
        $bad ? $this->fail('Alguns esdeveniments no s\'han pogut configurar: ' . json_encode($bad, JSON_UNESCAPED_UNICODE), '/configuracio#mailjet')
            : $this->done('Webhooks configurats a Mailjet (' . implode(', ', array_keys($r)) . ').', '/configuracio#mailjet');
    }

    public function testAi(): never
    {
        $r = (new Ai())->test();
        if (!$r) {
            $this->fail('No hi ha cap servei d\'IA configurat.', '/configuracio#ai');
        }
        $bad = array_filter($r, static fn ($x) => !$x['ok']);
        $text = implode(' ', array_map(static fn ($x) => $x['message'], $r));
        $bad ? $this->fail($text, '/configuracio#ai') : $this->done($text, '/configuracio#ai');
    }
}
