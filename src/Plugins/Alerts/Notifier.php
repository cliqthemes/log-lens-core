<?php
declare(strict_types=1);

namespace LogLens\Plugins\Alerts;

use LogLens\Support\HttpClient;

/**
 * Delivers an alert to a channel. Slack and Discord take their incoming-webhook
 * JSON shapes; a generic "webhook" channel receives the structured alert as-is
 * so it can be routed anywhere. The HTTP transport is injectable for tests.
 */
final class Notifier
{
    public const TYPES = ['slack', 'discord', 'webhook'];

    /** @var callable(string,array<string,string>,string):array{status:int,body:string} */
    private $transport;

    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $transport */
    public function __construct(?callable $transport = null, private readonly int $timeout = 10)
    {
        $this->transport = $transport ?? function (string $url, array $headers, string $body): array {
            // publicOnly: a channel URL is operator-supplied and fetched by the
            // server, so the destination is re-validated and pinned on every
            // attempt rather than trusted from when it was saved. See
            // LogLens\Support\OutboundUrlGuard.
            return (new HttpClient($this->timeout, min($this->timeout, 5), 2, null, true))
                ->post($url, $body, $headers);
        };
    }

    /**
     * Deliver an alert. Returns [ok, status, detail]. Never throws on a delivery
     * failure — the caller records it and moves on.
     *
     * @param array<string,mixed> $alert
     * @return array{ok:bool,status:int,detail:string}
     */
    public function deliver(string $type, string $url, array $alert): array
    {
        $body = $this->format($type, $alert);
        try {
            $response = ($this->transport)($url, ['Content-Type' => 'application/json'], $body);
            $status = (int) ($response['status'] ?? 0);
            $ok = $status >= 200 && $status < 300;
            return ['ok' => $ok, 'status' => $status, 'detail' => $ok ? 'delivered' : ('HTTP ' . $status)];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'status' => 0, 'detail' => $exception->getMessage()];
        }
    }

    /** @param array<string,mixed> $alert */
    public function format(string $type, array $alert): string
    {
        $text = $this->text($alert);
        return match ($type) {
            'slack' => (string) json_encode(['text' => $text], JSON_UNESCAPED_SLASHES),
            'discord' => (string) json_encode(['content' => $text], JSON_UNESCAPED_SLASHES),
            default => (string) json_encode($alert, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        };
    }

    /** @param array<string,mixed> $alert */
    private function text(array $alert): string
    {
        $icons = ['new_error' => '🚨', 'reoccurrence' => '🔁', 'spike' => '📈', 'test' => '✅'];
        $labels = ['new_error' => 'New error', 'reoccurrence' => 'Reoccurred', 'spike' => 'Spike', 'test' => 'Test alert'];
        $trigger = (string) ($alert['trigger'] ?? 'new_error');
        $lines = [];
        $lines[] = sprintf(
            '%s *%s* — %s',
            $icons[$trigger] ?? '🔔',
            $labels[$trigger] ?? ucfirst($trigger),
            (string) ($alert['summary'] ?? 'Alert from Log Lens'),
        );
        foreach (($alert['items'] ?? []) as $item) {
            $lines[] = sprintf(
                '• [%s] %s%s',
                (string) ($item['severity'] ?? 'INFO'),
                (string) ($item['title'] ?? 'Untitled'),
                isset($item['count']) ? ' (' . (int) $item['count'] . '×)' : '',
            );
        }
        if (!empty($alert['more'])) {
            $lines[] = sprintf('…and %d more', (int) $alert['more']);
        }
        if (!empty($alert['url'])) {
            $lines[] = (string) $alert['url'];
        }
        return implode("\n", $lines);
    }
}
