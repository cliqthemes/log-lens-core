<?php
declare(strict_types=1);

namespace LogLens\Connectors;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Contracts\LogSourceConnectorInterface;
use LogLens\Domain\RemoteLogFile;
use RuntimeException;

final class LocalDirectoryConnector implements LogSourceConnectorInterface
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function discover(): array
    {
        $directory = $this->directory();
        $recursive = (bool) ($this->config['recursive'] ?? true);
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS))
            : new \IteratorIterator(new \DirectoryIterator($directory));
        $pattern = Config::pattern('ingestion.log_file_pattern', '/\.log(?:\.\d+)?$/i');
        $files = [];
        foreach ($iterator as $item) {
            if (!$item->isFile() || preg_match($pattern, $item->getFilename()) !== 1) {
                continue;
            }
            $stat = $item->getFileInfo();
            $nativeStat = stat($item->getPathname());
            $files[] = new RemoteLogFile(
                $item->getPathname(),
                ($nativeStat['dev'] ?? 0) . ':' . $stat->getInode(),
                $stat->getSize(),
                $stat->getMTime(),
                $item->getFilename(),
            );
        }
        usort($files, static fn (RemoteLogFile $a, RemoteLogFile $b): int => strcmp($a->path, $b->path));
        return $files;
    }

    public function readRange(RemoteLogFile $file, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0) {
            throw new InvalidArgumentException('Connector byte ranges cannot be negative.');
        }
        $handle = fopen($file->path, 'rb');
        if ($handle === false || fseek($handle, $offset) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException("Cannot read local source {$file->path}.");
        }
        $content = $length === 0 ? '' : fread($handle, $length);
        fclose($handle);
        if ($content === false) {
            throw new RuntimeException("Cannot read local source {$file->path}.");
        }
        return $content;
    }

    public function prefixHash(RemoteLogFile $file, int $length): string
    {
        return hash('sha256', $this->readRange($file, 0, $length));
    }

    public function prefixHashes(array $requests): array
    {
        return array_map(
            fn (array $request): string => $this->prefixHash($request['file'], $request['length']),
            $requests,
        );
    }

    public function test(): array
    {
        $files = $this->discover();
        return ['ok' => true, 'message' => 'Connection succeeded; discovered ' . count($files) . ' log file(s).'];
    }

    private function directory(): string
    {
        $directory = trim((string) ($this->config['directory'] ?? ''));
        if ($directory === '' || !is_dir($directory)) {
            throw new InvalidArgumentException('The configured local log directory does not exist.');
        }
        return realpath($directory) ?: $directory;
    }
}
