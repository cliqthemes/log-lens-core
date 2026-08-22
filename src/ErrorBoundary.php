<?php
declare(strict_types=1);

namespace LogLens;

/**
 * Guarantees the standalone API only ever emits JSON.
 *
 * The dispatcher's try/catch handles thrown exceptions, but PHP warnings,
 * notices, and fatal errors (out of memory, max_execution_time, etc.) bypass it
 * and, with display_errors on, print an HTML `<br /><b>…` fragment that corrupts
 * the JSON body. This boundary:
 *   - turns off HTML error output (errors go to the log, never the response);
 *   - captures output so any stray notice can be discarded before the JSON;
 *   - converts a fatal shutdown into a clean JSON 500.
 */
final class ErrorBoundary
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR
        | E_USER_ERROR | E_RECOVERABLE_ERROR;

    public static function install(int $timeLimitSeconds = 300): void
    {
        @ini_set('display_errors', '0');
        @ini_set('html_errors', '0');
        error_reporting(E_ALL);
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeLimitSeconds);
        }

        ob_start();

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false; // respect @-suppression and the current error level
            }
            error_log(sprintf('[log-lens] %s in %s:%d', $message, $file, $line));
            return true; // handled — never echoed into the response
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || ($error['type'] & self::FATAL) === 0) {
                return;
            }
            error_log(sprintf('[log-lens] fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));
            if (headers_sent()) {
                return;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'The server hit a fatal error while handling the request. Check the PHP error log.',
            ]);
        });
    }
}
