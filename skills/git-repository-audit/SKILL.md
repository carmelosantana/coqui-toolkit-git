---
name: git-repository-audit
description: Repository triage skill for Git. Use when the user asks to assess a codebase before reading code, identify churn hotspots, bug clusters, bus factor, commit velocity, or firefighting patterns.
license: MIT
tags: ["git", "audit", "repository-analysis", "codebase-triage"]
metadata:
  author: coquibot
  version: "1.0"
---

# Git Repository Audit

Use this skill when the goal is to understand where a repository hurts before editing or even reading the code in depth.

## Discovery Workflow

Start with the high-level repository signals:

1. `git_churn_hotspots(period: "1-year")` to find the files that absorb the most change.
2. `git_bug_hotspots(period: "1-year")` to find the files that repeatedly appear in fix-heavy commits.
3. `git_contributor_ranking(period: "all-time")` to identify ownership concentration and maintainer drift.
4. `git_velocity_trend(period: "all-time", granularity: "month")` to determine whether delivery is steady, accelerating, or fading.
5. `git_crisis_detection(period: "1-year")` to detect reverts, hotfixes, and rollback-heavy release pressure.

## How To Interpret The Signals

- High churn alone can mean active development.
- High churn plus bug-fix overlap is a stronger signal of fragile code.
- A top contributor above roughly 60% of commits is a bus-factor warning.
- An all-time top contributor disappearing from the recent window is a continuity risk.
- Declining velocity can indicate team shrinkage, deprioritization, or release freezes.
- Frequent revert and hotfix commits often point to deploy fear or weak test coverage.

## Recommended Follow-Up

Once you know where the risk is, move from analysis to direct inspection:

1. `git_log(file: "path/to/file", count: 10, oneline: true)` to inspect the recent history of a hotspot.
2. `git_diff(scope: "commits", ref1: "HEAD~5", file: "path/to/file")` to see the latest shape of change in that area.
3. `git_status()` and `git_branch(action: "current")` before making any edits.

## Practical Audit Patterns

### Before touching a risky file

1. Run `git_churn_hotspots`.
2. Cross-check the same window with `git_bug_hotspots`.
3. Inspect the overlapping file with `git_log(file: ...)`.
4. Only then start reading implementation details.

### Before inheriting a repository

1. Run `git_contributor_ranking(period: "all-time")`.
2. Run `git_contributor_ranking(period: "6-months")`.
3. Compare leadership continuity and active maintainer spread.

### Before evaluating delivery health

1. Run `git_velocity_trend(period: "all-time", granularity: "month")`.
2. Run `git_crisis_detection(period: "1-year")`.
3. Treat declining velocity plus crisis traffic as a warning that release pressure is degrading delivery quality.

## Best Practices

- Keep time windows explicit so comparisons are meaningful.
- Prefer the analysis tools first; use raw history and diff tools second.
- Do not treat commit message keywords as perfect truth. Poor message hygiene weakens bug and crisis signals.
- Use the output to prioritize reading order, not to make absolute quality judgments in isolation.