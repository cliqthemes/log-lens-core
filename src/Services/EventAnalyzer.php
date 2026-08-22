<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Domain\EventAnalysis;
use LogLens\Domain\LogEvent;

final class EventAnalyzer
{
    public function analyze(LogEvent $event): EventAnalysis
    {
        $body = $event->body;
        $first = trim(strtok($body, "\n") ?: $body);
        $context = $event->context === [] ? '' : (json_encode($event->context, JSON_UNESCAPED_SLASHES) ?: '');
        $extra = '';

        if ($context === '' && ($position = strpos($body, ' {')) !== false) {
            $context = substr($body, $position + 1);
            if (($firstJson = strpos($first, ' {')) !== false) {
                $first = substr($first, 0, $firstJson);
            }
        }
        $decoded = json_decode($context, true);
        if (is_array($decoded)) {
            $extra = (string) ($decoded['exception'] ?? $decoded['error'] ?? '');
        }

        $all = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $body . "\n" . $extra);
        $exception = '';
        if (preg_match('/\[object\]\s*\(([^(:]+)|\b([A-Za-z_\\\\]+(?:Exception|Error))\b/', $all, $match) === 1) {
            $exception = trim($match[1] ?: $match[2]);
        }

        $frames = [];
        if (preg_match_all('/^#\d+\s+(.+?\.php)\((\d+)\)/m', $all, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $frames[] = $match[1] . ':' . $match[2];
            }
        }
        // The culprit frame is the first one that is *not* library code. The
        // old test was the inverse — the first frame containing '/app/' — which
        // is exactly wrong for the most common Laravel deployment there is: a
        // container rooted at /app, where every framework frame reads
        // /app/vendor/laravel/… and matches too. The culprit then became a
        // framework internal, and because the frame feeds the fingerprint, two
        // occurrences of one error whose stacks entered the framework at
        // different depths split into two unrelated issues. '/vendor/' is the
        // same marker StackTraceFormatter::withoutVendor() uses, so the frame
        // shown as the culprit is one that survives the skip_vendor view.
        $sourceFrame = '';
        foreach ($frames as $candidate) {
            if (!str_contains($candidate, '/vendor/') && !str_contains($candidate, '/node_modules/')) {
                $sourceFrame = $candidate;
                break;
            }
        }
        $sourceFrame = $sourceFrame ?: ($frames[0] ?? '');

        $stack = '';
        if (($stackPosition = stripos($all, '[stacktrace]')) !== false) {
            $stack = trim(substr($all, $stackPosition + 12));
        } elseif ($frames !== []) {
            $stack = implode("\n", $frames);
        } elseif (str_contains($body, "\n")) {
            $stack = trim(substr($body, strpos($body, "\n") + 1));
        }

        return new EventAnalysis(
            $this->shortTitle($first),
            $context,
            $exception,
            $sourceFrame,
            $stack,
        );
    }

    public function normalize(string $value): string
    {
        // Order matters: specific patterns must run before the generic \d+ rule,
        // otherwise every digit becomes {n} and the later patterns never match.
        $patterns = [
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/\b\d{4}-\d\d-\d\d[ tT]\d\d:\d\d:\d\d[^\s]*/' => '{time}',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '{ip}',
            '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i' => '{email}',
            '/\/releases\/[^\/\s]+\//' => '/releases/{release}/',
            '/:\d+\b/' => ':{line}',
            '/\b\d+\b/' => '{n}',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }
        return preg_replace('/\s+/', ' ', trim(strtolower($value))) ?? trim($value);
    }

    private function shortTitle(string $title): string
    {
        $title = preg_replace('/\s+/', ' ', trim($title)) ?? trim($title);
        foreach ([' (Connection:', ' {"exception"', ' SQL: insert into', ' SQL: update '] as $cut) {
            if (($position = stripos($title, $cut)) !== false) {
                $title = substr($title, 0, $position);
            }
        }
        return strlen($title) > 180 ? substr($title, 0, 177) . '…' : $title;
    }
}
