<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CoquiBot\Toolkits\Git\Analysis\GitAnalysisService;

/**
 * Rank files by bug-fix traffic and overlap them with churn hotspots.
 */
final readonly class GitBugHotspotsTool
{
    public function __construct(
        private GitAnalysisService $analysis,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_bug_hotspots',
            description: 'Find files that show up repeatedly in bug-fix commits and compare them against churn hotspots.',
            parameters: [
                new EnumParameter(
                    'period',
                    'Time window to analyze.',
                    values: ['1-month', '3-months', '6-months', '1-year', 'all-time'],
                    required: false,
                ),
                new StringParameter(
                    'keywords',
                    'Case-insensitive regex used to match bug-fix commit messages (default: "fix|bug|broken").',
                    required: false,
                ),
                new NumberParameter(
                    'limit',
                    'Maximum number of files to show (default: 20).',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 50,
                ),
                new StringParameter(
                    'path',
                    'Repository path. Defaults to the current working directory.',
                    required: false,
                ),
            ],
            callback: fn(array $args) => $this->analysis->bugHotspots($args),
        );
    }
}