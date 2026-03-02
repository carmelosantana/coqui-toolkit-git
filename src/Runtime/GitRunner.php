<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Runtime;

/**
 * Core process runner for Git CLI commands.
 *
 * Resolves the git binary, builds commands with proper argument escaping,
 * and executes via proc_open() with non-blocking output reads, timeout
 * support, and output truncation.
 */
final class GitRunner
{
    private const int DEFAULT_TIMEOUT = 30;
    private const int MAX_OUTPUT_BYTES = 65_536;

    /** Cached git binary path */
    private string $resolvedBinary = '';

    public function __construct(
        private readonly string $defaultRepoPath = '',
    ) {}

    /**
     * Execute a git subcommand and return the result.
     *
     * @param list<string> $args    Arguments for the git subcommand
     * @param string       $repoPath Override the working directory (defaults to defaultRepoPath)
     * @param int          $timeout  Seconds before the process is killed (0 = no timeout)
     */
    public function run(
        string $subcommand,
        array $args = [],
        string $repoPath = '',
        int $timeout = self::DEFAULT_TIMEOUT,
    ): GitResult {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            return new GitResult(127, '', 'git not found. Install git (e.g. sudo apt install git).');
        }

        $parts = [escapeshellarg($binary), $subcommand];

        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        $command = implode(' ', $parts);
        $cwd = $repoPath !== '' ? $repoPath : $this->defaultRepoPath;

        if ($cwd === '') {
            $cwd = getcwd() ?: '.';
        }

        return $this->execute($command, $cwd, $timeout);
    }

    /**
     * Run a raw git command string (for complex piped/redirect scenarios).
     */
    public function runRaw(
        string $command,
        string $repoPath = '',
        int $timeout = self::DEFAULT_TIMEOUT,
    ): GitResult {
        $cwd = $repoPath !== '' ? $repoPath : $this->defaultRepoPath;

        if ($cwd === '') {
            $cwd = getcwd() ?: '.';
        }

        return $this->execute($command, $cwd, $timeout);
    }

    /**
     * Resolve the absolute path to the git binary.
     */
    public function resolveBinary(): string
    {
        if ($this->resolvedBinary !== '') {
            return $this->resolvedBinary;
        }

        $which = trim((string) shell_exec('which git 2>/dev/null'));
        if ($which !== '' && file_exists($which)) {
            $this->resolvedBinary = $which;
            return $which;
        }

        return '';
    }

    /**
     * Check if git is available on the system.
     */
    public function isAvailable(): bool
    {
        return $this->resolveBinary() !== '';
    }

    private function execute(string $command, string $cwd, int $timeout): GitResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            return new GitResult(1, '', 'Failed to start process: ' . $command);
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $out = stream_get_contents($pipes[1]) ?: '';
            $err = stream_get_contents($pipes[2]) ?: '';

            $stdout .= $out;
            $stderr .= $err;

            if (!$status['running']) {
                break;
            }

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                proc_terminate($process, 15); // SIGTERM
                usleep(100_000);
                proc_terminate($process, 9);  // SIGKILL
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new GitResult(
                    124,
                    $this->truncateOutput($stdout),
                    "Command timed out after {$timeout}s.\n" . $this->truncateOutput($stderr),
                );
            }

            usleep(10_000);
        }

        // Read any remaining output after process exits
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new GitResult(
            $exitCode,
            $this->truncateOutput(trim($stdout)),
            $this->truncateOutput(trim($stderr)),
        );
    }

    private function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES)
            . "\n\n[Output truncated at " . self::MAX_OUTPUT_BYTES . ' bytes]';
    }
}
