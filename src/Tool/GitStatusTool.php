<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;

/**
 * Show the working tree status.
 */
final readonly class GitStatusTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_status',
            description: 'Show the working tree status — modified, staged, and untracked files.',
            parameters: [
                new StringParameter(
                    'path',
                    'Repository path. Defaults to the current working directory.',
                    required: false,
                ),
                new BoolParameter(
                    'short',
                    'Use short format output (compact, one line per file).',
                    required: false,
                ),
            ],
            callback: fn(array $args) => $this->execute($args),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function execute(array $args): ToolResult
    {
        $path = trim((string) ($args['path'] ?? ''));
        $short = (bool) ($args['short'] ?? false);

        $gitArgs = [];
        if ($short) {
            $gitArgs[] = '--short';
        }
        $gitArgs[] = '--branch';

        return $this->runner->run('status', $gitArgs, $path)->toToolResult();
    }
}
