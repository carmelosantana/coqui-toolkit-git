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
 * Run the full repository triage workflow and return a single machine-readable report.
 */
final readonly class GitRepoTriageTool
{
    public function __construct(
        private GitAnalysisService $analysis,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_repo_triage',
            description: 'Run all repository audit analyses at once and return a single compact JSON triage report for machine consumption.',
            parameters: [
                new EnumParameter(
                    'hotspot_period',
                    'Time window for churn and bug hotspot analysis.',
                    values: ['1-month', '3-months', '6-months', '1-year', 'all-time'],
                    required: false,
                ),
                new EnumParameter(
                    'contributor_period',
                    'Time window for contributor ranking.',
                    values: ['all-time', '1-year', '6-months', '3-months'],
                    required: false,
                ),
                new EnumParameter(
                    'velocity_period',
                    'Time window for velocity analysis.',
                    values: ['1-year', '2-years', 'all-time'],
                    required: false,
                ),
                new EnumParameter(
                    'velocity_granularity',
                    'How to group commit velocity data.',
                    values: ['month', 'quarter', 'year'],
                    required: false,
                ),
                new EnumParameter(
                    'crisis_period',
                    'Time window for crisis detection.',
                    values: ['1-month', '3-months', '6-months', '1-year'],
                    required: false,
                ),
                new NumberParameter(
                    'limit',
                    'Maximum number of hotspots or contributors to include in each section (default: 20).',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 50,
                ),
                new StringParameter(
                    'bug_keywords',
                    'Regex used to detect bug-fix commits (default: "fix|bug|broken").',
                    required: false,
                ),
                new StringParameter(
                    'crisis_keywords',
                    'Regex used to detect crisis-oriented commits (default: "revert|hotfix|emergency|rollback").',
                    required: false,
                ),
                new StringParameter(
                    'path',
                    'Repository path. Defaults to the current working directory.',
                    required: false,
                ),
            ],
            callback: fn(array $args) => $this->analysis->repoTriage($args),
        );
    }
}