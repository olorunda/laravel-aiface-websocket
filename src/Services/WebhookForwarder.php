<?php

namespace AiFace\WebSocket\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches hardware events asynchronously to external webhook endpoints with HMAC authentication.
 */
class WebhookForwarder
{
    protected array $config;
    protected bool $enabled;
    protected string $url;
    protected string $secret;
    protected int $timeout;
    protected array $allowedEvents;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->enabled = (bool) ($config['webhooks']['enabled'] ?? false);
        $this->url = (string) ($config['webhooks']['url'] ?? '');
        $this->secret = (string) ($config['webhooks']['secret'] ?? '');
        $this->timeout = (int) ($config['webhooks']['timeout'] ?? 5);
        $this->allowedEvents = (array) ($config['webhooks']['events'] ?? []);
    }

    /**
     * Dispatch webhook event.
     */
    public function dispatch(string $event, array $payload): void
    {
        if (!$this->enabled || empty($this->url)) {
            return;
        }

        if (!empty($this->allowedEvents) && !in_array($event, $this->allowedEvents, true)) {
            return;
        }

        try {
            $timestamp = time();
            $body = json_encode([
                'event' => $event,
                'timestamp' => $timestamp,
                'data' => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $headers = [
                'Content-Type' => 'application/json',
                'X-AiFace-Event' => $event,
                'X-AiFace-Timestamp' => (string) $timestamp,
            ];

            if (!empty($this->secret)) {
                $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $this->secret);
                $headers['X-AiFace-Signature'] = 'sha256=' . $signature;
            }

            Http::timeout($this->timeout)->withHeaders($headers)->post($this->url, json_decode($body, true));
        } catch (\Throwable $e) {
            Log::warning(sprintf('AiFace Webhook forward failed for [%s]: %s', $event, $e->getMessage()));
        }
    }
}
