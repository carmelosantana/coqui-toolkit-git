<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CoquiBot\Toolkits\Git\Analysis\GitAnalysisService;

/**
 * Detect revert and hotfix patterns that suggest firefighting.
 */
final readonly class GitCrisisDetectionTool
{
    public function __construct(
        private GitAnalysisService $analysis,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_crisis_detection',
            description: 'Detect revert, hotfix, rollback, or emergency commit patterns that suggest a team is firefighting.',
            parameters: [
                new EnumParameter(
                    'period',
                    'Time window to analyze.',
                    values: ['1-month', '3-months', '6-months', '1-year'],
                    required: false,
                ),
                new StringParameter(
                    'keywords',
                    'Case-insensitive regex used to match crisis-oriented commit messages (default: "revert|hotfix|emergency|rollback").',
                    required: false,
                ),
                new StringParameter(
                    'path',
                    'Repository path. Defaults to the current working directory.',
                    required: false,
                ),
            ],
            callback: fn(array $args) => $this->analysis->crisisDetection($args),
        );
    }
}