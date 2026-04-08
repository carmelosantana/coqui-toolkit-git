<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CoquiBot\Toolkits\Git\Analysis\GitAnalysisService;

/**
 * Summarize commit velocity over time.
 */
final readonly class GitVelocityTrendTool
{
    public function __construct(
        private GitAnalysisService $analysis,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_velocity_trend',
            description: 'Summarize commit activity over time so you can see whether a project is steady, accelerating, declining, or volatile.',
            parameters: [
                new EnumParameter(
                    'period',
                    'Time window to analyze.',
                    values: ['1-year', '2-years', 'all-time'],
                    required: false,
                ),
                new EnumParameter(
                    'granularity',
                    'How to group commit activity.',
                    values: ['month', 'quarter', 'year'],
                    required: false,
                ),
                new StringParameter(
                    'path',
                    'Repository path. Defaults to the current working directory.',
                    required: false,
                ),
            ],
            callback: fn(array $args) => $this->analysis->velocityTrend($args),
        );
    }
}