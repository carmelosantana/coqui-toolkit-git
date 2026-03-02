<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;

/**
 * Manage Git remotes — list, add, remove, show.
 */
final readonly class GitRemoteTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_remote',
            description: 'Manage Git remotes — list configured remotes, add new ones, remove existing, or show details.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Remote operation to perform.',
                    values: ['list', 'add', 'remove', 'show'],
                    required: true,
                ),
                new StringParameter(
                    'name',
                    'Remote name (required for add, remove, show). Usually "origin".',
                    required: false,
                ),
                new StringParameter(
                    'url',
                    'Remote URL (required for add). Accepts HTTPS or SSH URLs.',
                    required: false,
                ),
                new StringParameter(
                    'path',
                    'Repository path. Defaults to the current working directory.',
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
        $action = trim((string) ($args['action'] ?? ''));
        $name = trim((string) ($args['name'] ?? ''));
        $url = trim((string) ($args['url'] ?? ''));
        $path = trim((string) ($args['path'] ?? ''));

        return match ($action) {
            'list' => $this->listRemotes($path),
            'add' => $this->addRemote($name, $url, $path),
            'remove' => $this->removeRemote($name, $path),
            'show' => $this->showRemote($name, $path),
            default => ToolResult::error("Unknown action: {$action}."),
        };
    }

    private function listRemotes(string $repoPath): ToolResult
    {
        $result = $this->runner->run('remote', ['--verbose'], $repoPath);
        $output = $result->output();

        if ($result->succeeded() && $output === '') {
            return ToolResult::success('No remotes configured.');
        }

        return $result->toToolResult();
    }

    private function addRemote(string $name, string $url, string $repoPath): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to add a remote.');
        }
        if ($url === '') {
            return ToolResult::error('The "url" parameter is required to add a remote.');
        }

        return $this->runner->run('remote', ['add', $name, $url], $repoPath)->toToolResult();
    }

    private function removeRemote(string $name, string $repoPath): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to remove a remote.');
        }

        return $this->runner->run('remote', ['remove', $name], $repoPath)->toToolResult();
    }

    private function showRemote(string $name, string $repoPath): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to show remote details.');
        }

        return $this->runner->run('remote', ['show', $name], $repoPath)->toToolResult();
    }
}
