<?php
declare(strict_types=1);

namespace LogLens\Connectors;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Contracts\LogSourceConnectorInterface;
use LogLens\Domain\RemoteLogFile;
use LogLens\Services\ProcessRunner;

final class SshConnector implements LogSourceConnectorInterface
{
    private const MAX_DISCOVERED_FILES = 10_000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly ?string $stateDirectory = null,
    ) {
    }

    public function discover(): array
    {
        /** @var array<string,RemoteLogFile> $files */
        $files = [];
        foreach ($this->paths() as $pathPattern) {
            $discovered = $this->isPattern($pathPattern)
                ? $this->discoverPattern($pathPattern)
                : [$this->discoverExactPath($pathPattern)];
            $maxFiles = Config::int('sync.max_discovered_files', self::MAX_DISCOVERED_FILES);
            foreach ($discovered as $file) {
                $files[$file->path] = $file;
                if (count($files) > $maxFiles) {
                    throw new InvalidArgumentException(
                        'SSH path patterns discovered more than ' . $maxFiles
                        . ' files. Narrow the configured patterns.'
                    );
                }
            }
        }
        ksort($files, SORT_STRING);
        return array_values($files);
    }

    public function readRange(RemoteLogFile $file, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0) {
            throw new InvalidArgumentException('Connector byte ranges cannot be negative.');
        }
        if ($length === 0) {
            return '';
        }
        $script = <<<'PHP'
$path=base64_decode($argv[1],true);
$offset=(int)$argv[2];
$remaining=(int)$argv[3];
$handle=$path===false?false:fopen($path,"rb");
if($handle===false||fseek($handle,$offset)!==0){fwrite(STDERR,"Could not read Log Lens source range.\n");exit(2);}
while($remaining>0&&!feof($handle)){
    $chunk=fread($handle,min(1048576,$remaining));
    if($chunk===false){fwrite(STDERR,"Could not read Log Lens source range.\n");exit(3);}
    if($chunk===""){break;}
    echo $chunk;
    $remaining-=strlen($chunk);
}
fclose($handle);
PHP;
        $command = 'php -r ' . escapeshellarg($script)
            . ' ' . escapeshellarg(base64_encode($file->path))
            . ' ' . $offset
            . ' ' . $length;
        return $this->remote($command, max(30, (int) ceil($length / 1_048_576) * 15));
    }

    public function prefixHash(RemoteLogFile $file, int $length): string
    {
        return $this->prefixHashes([['file' => $file, 'length' => $length]])[0];
    }

    public function prefixHashes(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $payload = [];
        $totalLength = 0;
        foreach ($requests as $request) {
            $file = $request['file'] ?? null;
            $length = (int) ($request['length'] ?? -1);
            if (!$file instanceof RemoteLogFile || $length < 0) {
                throw new InvalidArgumentException('Prefix-hash requests require a file and non-negative length.');
            }
            $payload[] = [base64_encode($file->path), $length];
            $totalLength += $length;
        }
        $script = <<<'PHP'
$requests=json_decode(base64_decode($argv[1],true)?:"",true);
if(!is_array($requests)){fwrite(STDERR,"Invalid Log Lens hash request.\n");exit(2);}
foreach($requests as $request){
    $path=base64_decode((string)($request[0]??""),true);
    $remaining=(int)($request[1]??-1);
    $handle=$path===false||$remaining<0?false:fopen($path,"rb");
    if($handle===false){fwrite(STDERR,"Could not hash Log Lens source.\n");exit(3);}
    $hash=hash_init("sha256");
    while($remaining>0&&!feof($handle)){
        $chunk=fread($handle,min(1048576,$remaining));
        if($chunk===false){fwrite(STDERR,"Could not hash Log Lens source.\n");exit(4);}
        if($chunk===""){break;}
        hash_update($hash,$chunk);
        $remaining-=strlen($chunk);
    }
    fclose($handle);
    echo hash_final($hash),"\n";
}
PHP;
        $output = trim($this->remote(
            'php -r ' . escapeshellarg($script)
            . ' ' . escapeshellarg(base64_encode(json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ))),
            max(30, (int) ceil($totalLength / 1_048_576) * 2),
        ));
        $hashes = $output === '' ? [] : preg_split('/\r?\n/', $output);
        if (count($hashes) !== count($requests)) {
            throw new InvalidArgumentException('The remote host returned an incomplete prefix-hash response.');
        }
        foreach ($hashes as &$hash) {
            $hash = strtolower(trim($hash));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('The remote host did not return a valid SHA-256 prefix hash.');
            }
        }
        unset($hash);
        return array_values($hashes);
    }

    public function test(): array
    {
        $version = trim($this->remote('printf log-lens-ready'));
        if ($version !== 'log-lens-ready') {
            throw new InvalidArgumentException('The SSH connection returned an unexpected response.');
        }
        $files = $this->discover();
        return ['ok' => true, 'message' => 'SSH succeeded; discovered ' . count($files) . ' configured log file(s).'];
    }

    private function remote(string $remoteCommand, int $timeout = 30): string
    {
        return $this->runner->run([...$this->command(), $this->target(), $remoteCommand], $timeout);
    }

    /** @return list<string> */
    private function command(): array
    {
        $port = (int) ($this->config['port'] ?? Config::int('ssh.default_port', 22));
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('SSH port must be between 1 and 65535.');
        }
        $command = [
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=' . Config::int('ssh.connect_timeout', 10),
            '-p', (string) $port,
        ];
        $keyPath = $this->localPath($this->config['key_path'] ?? '', 'SSH private key path');
        if ($keyPath !== '') {
            array_push($command, '-i', $keyPath, '-o', 'IdentitiesOnly=yes');
        }
        $knownHosts = $this->localPath($this->config['known_hosts_file'] ?? '', 'Known-hosts file path');
        if ($knownHosts !== '') {
            // An explicit file is the operator's pinned trust anchor: unknown or changed keys fail.
            array_push($command, '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' . $knownHosts);
            return $command;
        }
        $managed = $this->managedKnownHosts();
        if ($managed !== null) {
            // The process user's ~/.ssh/known_hosts is not reliable (containers, FPM users, Lerd/Docker), so
            // keep a Log Lens-owned file: a new host is trusted on first use, a CHANGED key is still refused.
            array_push($command, '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . $managed);
        } else {
            array_push($command, '-o', 'StrictHostKeyChecking=yes');
        }
        return $command;
    }

    private function managedKnownHosts(): ?string
    {
        if ($this->stateDirectory === null || $this->stateDirectory === '') {
            return null;
        }
        $directory = rtrim($this->stateDirectory, '/') . '/.ssh';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }
        return $directory . '/known_hosts';
    }

    /**
     * Validate a local file path that goes into the ssh argument vector.
     *
     * Two things are deliberate here.
     *
     * Shape is checked, existence is not. The old code answered "the configured
     * SSH private key does not exist" — a per-path yes/no that turned the
     * connector form into a file-existence oracle for the whole filesystem the
     * PHP process can stat. ssh reports a missing identity or known-hosts file
     * perfectly well on its own, through the connection error, without Log Lens
     * confirming what does and does not exist on disk.
     *
     * The path must be absolute and free of control characters. It cannot be
     * shell-injected — ProcessRunner passes an argv array, never a shell string
     * — but `known_hosts_file` is concatenated into an `-o Name=value` directive,
     * and a value carrying a newline would be handing ssh a second config
     * directive of the caller's choosing. A relative path is rejected because it
     * would resolve against the web server's working directory, which is not
     * something an operator can reason about.
     *
     * @param  mixed  $value
     */
    private function localPath(mixed $value, string $label): string
    {
        $path = trim((string) (is_scalar($value) ? $value : ''));
        if ($path === '') {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException($label . ' must not contain control characters.');
        }
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException($label . ' must be an absolute path.');
        }
        return $path;
    }

    private function target(): string
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $user = trim((string) ($this->config['user'] ?? ''));
        if (
            preg_match('/^[a-zA-Z0-9._:-]+$/', $host) !== 1
            || preg_match('/^[a-zA-Z0-9._-]+$/', $user) !== 1
        ) {
            throw new InvalidArgumentException('SSH host and user are required and contain unsupported characters.');
        }
        return $user . '@' . $host;
    }

    /** @return list<string> */
    private function paths(): array
    {
        $paths = $this->config['paths'] ?? [];
        if (!is_array($paths)) {
            throw new InvalidArgumentException('SSH paths must be an array.');
        }
        $paths = array_values(array_unique(array_filter(
            array_map(static fn (mixed $path): string => trim((string) $path), $paths),
            static fn (string $path): bool => $path !== '',
        )));
        if ($paths === [] || count($paths) > 100) {
            throw new InvalidArgumentException('Configure between 1 and 100 absolute SSH log paths or patterns.');
        }
        foreach ($paths as $path) {
            if (
                !str_starts_with($path, '/')
                || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
                || in_array('..', explode('/', $path), true)
                || str_contains($path, '**')
            ) {
                throw new InvalidArgumentException(
                    'SSH paths must be absolute, contain no traversal or control characters, and use only single-level globs.'
                );
            }
        }
        return $paths;
    }

    private function discoverExactPath(string $path): RemoteLogFile
    {
        $script = <<<'PHP'
$path=base64_decode($argv[1],true);
$stat=$path===false?false:stat($path);
if($stat===false||!is_file($path)){fwrite(STDERR,"Could not inspect Log Lens source.\n");exit(2);}
echo $stat["dev"],":",$stat["ino"],"|",$stat["size"],"|",$stat["mtime"];
PHP;
        $output = trim($this->remote(
            'php -r ' . escapeshellarg($script) . ' ' . escapeshellarg(base64_encode($path))
        ));
        $parts = explode('|', $output);
        if (
            count($parts) !== 3
            || preg_match('/^\d+:\d+$/', $parts[0]) !== 1
            || !ctype_digit($parts[1])
            || !ctype_digit($parts[2])
        ) {
            throw new InvalidArgumentException("Could not inspect remote log {$path}.");
        }
        return new RemoteLogFile($path, $parts[0], (int) $parts[1], (int) $parts[2], basename($path));
    }

    /** @return list<RemoteLogFile> */
    private function discoverPattern(string $pattern): array
    {
        $script = <<<'PHP'
$pattern=base64_decode($argv[1],true);
if($pattern===false){fwrite(STDERR,"Invalid Log Lens path pattern.\n");exit(2);}
$matches=glob($pattern,GLOB_NOSORT);
if($matches===false){fwrite(STDERR,"Could not expand Log Lens path pattern.\n");exit(3);}
foreach($matches as $path){
    if(!is_file($path)){continue;}
    $stat=stat($path);
    if($stat===false){continue;}
    echo base64_encode($path),"|",$stat["dev"],":",$stat["ino"],"|",$stat["size"],"|",$stat["mtime"],"\n";
}
PHP;
        $output = $this->remote(
            'php -r ' . escapeshellarg($script) . ' ' . escapeshellarg(base64_encode($pattern))
        );
        if ($output === '') {
            return [];
        }
        $files = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $fields = explode('|', $line);
            if (count($fields) !== 4) {
                throw new InvalidArgumentException("Could not parse files discovered for SSH pattern {$pattern}.");
            }
            [$encodedPath, $identity, $size, $modifiedAt] = $fields;
            $path = base64_decode($encodedPath, true);
            if (
                $path === false
                || !str_starts_with($path, '/')
                || preg_match('/^\d+:\d+$/', $identity) !== 1
                || !ctype_digit($size)
                || !ctype_digit($modifiedAt)
            ) {
                throw new InvalidArgumentException("The SSH host returned invalid metadata for pattern {$pattern}.");
            }
            $files[] = new RemoteLogFile(
                $path,
                $identity,
                (int) $size,
                (int) floor((float) $modifiedAt),
                basename($path),
            );
        }
        return $files;
    }

    private function isPattern(string $path): bool
    {
        return strpbrk($path, '*?[') !== false;
    }
}
