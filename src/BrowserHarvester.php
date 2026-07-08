<?php
declare(strict_types=1);

namespace CryptCrawler;

use RuntimeException;

final class BrowserHarvester
{
    public function __construct(
        private readonly string $nodeScriptPath
    ) {
    }

    /**
     * @param callable(array<string,mixed>):void $onListing
     * @param callable(array<string,mixed>):void $onProgress
     * @param callable():bool $shouldStop
     */
    public function run(
        string $source,
        string $term,
        string $url,
        int $maxScrolls,
        callable $onListing,
        callable $onProgress,
        callable $shouldStop
    ): void {
        if (!is_file($this->nodeScriptPath)) {
            throw new RuntimeException('Browser harvester script not found at: ' . $this->nodeScriptPath);
        }
        if (!function_exists('proc_open') || !function_exists('proc_get_status') || !function_exists('proc_terminate')) {
            throw new RuntimeException('proc_* functions are required for browser harvesting.');
        }

        $cmd = [
            'node',
            $this->nodeScriptPath,
            '--source=' . $source,
            '--term=' . $term,
            '--url=' . $url,
            '--scrolls=' . (string)$maxScrolls,
            '--wait=2000',
        ];
        $command = implode(' ', array_map('escapeshellarg', $cmd));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, dirname($this->nodeScriptPath));
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Node browser harvester process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdoutBuffer = '';
        $stderrBuffer = '';
        while (true) {
            if ($shouldStop()) {
                proc_terminate($process);
                break;
            }

            $status = proc_get_status($process);
            $running = (bool)($status['running'] ?? false);

            $out = stream_get_contents($pipes[1]);
            if ($out !== false && $out !== '') {
                $stdoutBuffer .= $out;
                $stdoutBuffer = $this->drainJsonLines($stdoutBuffer, $onListing, $onProgress);
            }

            $err = stream_get_contents($pipes[2]);
            if ($err !== false && $err !== '') {
                $stderrBuffer .= $err;
            }

            if (!$running) {
                $stdoutBuffer = $this->drainJsonLines($stdoutBuffer, $onListing, $onProgress);
                break;
            }
            usleep(150000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0 && !$shouldStop()) {
            throw new RuntimeException('Browser harvester failed: ' . trim($stderrBuffer));
        }
    }

    /**
     * @param callable(array<string,mixed>):void $onListing
     * @param callable(array<string,mixed>):void $onProgress
     */
    private function drainJsonLines(string $buffer, callable $onListing, callable $onProgress): string
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
            if ($line === '') {
                continue;
            }
            $payload = json_decode($line, true);
            if (!is_array($payload)) {
                continue;
            }
            $type = (string)($payload['type'] ?? '');
            if ($type === 'listing' && isset($payload['data']) && is_array($payload['data'])) {
                $onListing($payload['data']);
            } elseif ($type === 'batch' && isset($payload['data']) && is_array($payload['data'])) {
                foreach ($payload['data'] as $row) {
                    if (is_array($row)) {
                        $onListing($row);
                    }
                }
            } elseif ($type === 'progress') {
                $onProgress($payload);
            }
        }
        return $buffer;
    }
}
