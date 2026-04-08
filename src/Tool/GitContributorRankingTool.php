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
 * Rank repository contributors and flag bus-factor risk.
 */
final readonly class GitContributorRankingTool
{
    public function __construct(
        private GitAnalysisService $analysis,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_contributor_ranking',
            description: 'Rank contributors by commit volume and flag bus-factor or maintainer-activity risks.',
            parameters: [
                new EnumParameter(
                    'period',
                    'Time window to analyze.',
                    values: ['all-time', '1-year', '6-months', '3-months'],
                    required: false,
                ),
                new EnumParameter(
                    'merge_strategy',
                    'Whether merge commits should count toward author ranking.',
                    values: ['ignore-merges', 'count-merges'],
                    required: false,
                ),
                new NumberParameter(
                    'limit',
                    'Maximum number of contributors to show (default: 20).',
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
            callback: fn(array $args) => $this->analysis->contributorRanking($args),
        );
    }
}