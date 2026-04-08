<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CoquiBot\Toolkits\Git\Analysis\GitAnalysisService;

/**
 * Rank the highest-churn files in a repository.
 */
final readonly class GitChurnHotspotsTool
{
    public function __construct(
        private GitAnalysisService $analysis,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_churn_hotspots',
            description: 'Identify the files that changed most often in a time window so you can spot risky hotspots before reading code.',
            parameters: [
                new EnumParameter(
                    'period',
                    'Time window to analyze.',
                    values: ['1-month', '3-months', '6-months', '1-year', 'all-time'],
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
            callback: fn(array $args) => $this->analysis->churnHotspots($args),
        );
    }
}