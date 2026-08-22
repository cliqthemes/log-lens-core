<?php
declare(strict_types=1);

namespace LogLens\Parsing;

use LogLens\Config;
use LogLens\Contracts\LogParserInterface;
use RuntimeException;

final class ParserRegistry
{
    /** @param list<LogParserInterface>|null $parsers */
    public function __construct(private readonly ?array $parsers = null)
    {
    }

    public function forFile(string $path): LogParserInterface
    {
        $sample = $this->sample($path);
        foreach ($this->parsers ?? $this->resolveDefaults() as $parser) {
            if ($parser->supports($path, $sample)) {
                return $parser;
            }
        }
        throw new RuntimeException('No parser supports ' . basename($path));
    }

    /**
     * Config-declared parsers (mirroring
     * {@see \LogLens\Plugins\PluginRegistry}'s `plugins.register`) tried
     * first, so a host can register a more specific format before falling
     * through to the built-ins — critical since {@see GenericConsoleLogParser}
     * unconditionally supports everything and must stay last.
     *
     * @return list<LogParserInterface>
     */
    private function resolveDefaults(): array
    {
        return [...$this->configDeclaredParsers(), ...$this->defaults()];
    }

    /** @return list<LogParserInterface> */
    private function defaults(): array
    {
        return [
            // Laravel-framed horizon file first, then the same file in the
            // framing `horizon`/`queue:work` print to the console. Both claim
            // only horizon-named files, and both verify the framing before
            // claiming one, so a horizon log in neither shape still falls
            // through to the generic console parser rather than parsing to
            // nothing.
            new LaravelLogParser('horizon'),
            new HorizonLogParser(),
            new NginxAccessLogParser(),
            new LaravelLogParser(),
            new GenericConsoleLogParser(),
        ];
    }

    /** @return list<LogParserInterface> */
    private function configDeclaredParsers(): array
    {
        $declared = Config::get('parsing.register', []);
        if (!is_array($declared)) {
            return [];
        }
        $parsers = [];
        foreach ($declared as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            $instance = new $class();
            if ($instance instanceof LogParserInterface) {
                $parsers[] = $instance;
            }
        }
        return $parsers;
    }

    private function sample(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot inspect log file: {$path}");
        }
        $sample = fread($handle, Config::int('ingestion.sample_bytes', 65_536)) ?: '';
        fclose($handle);
        return $sample;
    }
}
