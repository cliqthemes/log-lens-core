<?php
declare(strict_types=1);

namespace LogLens\Http;

final class JsonResponse
{
    public static function send(mixed $data, int $status = 200): never
    {
        // Drop any stray output (a notice/warning that slipped through) so the
        // body is always clean JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /** Emit a dispatcher result over the standalone (superglobal) transport. */
    public static function emit(LogLensResponse $response): never
    {
        self::send($response->data, $response->status);
    }
}
