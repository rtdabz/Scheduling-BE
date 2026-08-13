# Schedule Quality Optimization

## Previous generation flow

1. `RuleEngine` and scheduling policy define hard constraints.
2. `CSPSolver` searches for valid schedules and applies internal search heuristics.
3. Callers generally accepted the first ranked CSP result.
4. The selected result was validated again by `RuleEngine` before persistence.

## Current generation flow

1. `RuleEngine` and scheduling policy continue to define hard constraints.
2. `CSPSolver` produces a pool of valid, diverse candidates.
3. `ScheduleQualityEvaluator` scores each complete candidate.
4. `ScheduleCandidateOptimizer` ranks candidates by descending quality score.
5. Only the highest-quality candidate is selected; final Rule Engine validation remains unchanged.

The evaluator does not generate meetings, assign rooms, or relax constraints. A candidate must first be valid according to the existing CSP and Rule Engine behavior.

## Quality score

The final score is higher-is-better and is the sum of four component scores:

```
resource usage
+ fair distribution
+ schedule compactness
+ configuration compliance
```

Each component begins at 250,000 points. Measured penalties are deducted from the relevant component. The API also returns `penalty_score`, `quality_breakdown`, and the individual penalty values in `score_breakdown` for diagnostics.

### Resource usage

- Penalizes unnecessary online conversion relative to department room-capacity targets.
- Penalizes eligible online meetings when physical rooms are unused.
- Prefers laboratory meetings in laboratory rooms.
- Rewards efficient distribution and consecutive use of physical rooms.

### Fair distribution

- Compares each section's physical-meeting rate instead of raw meeting counts.
- Penalizes F2F percentage spread and variance across all generated sections.
- Penalizes an early generated section when its F2F rate is materially higher than the average rate of later sections.
- Compares laboratory access rates separately.
- Penalizes deviations from capacity-based physical and online targets.
- Penalizes one section receiving a dominant share of physical meetings.
- Compares physical access rates between year levels when a candidate contains multiple year levels.

Capacity-based online targets are treated as an expected scarcity allocation. Online meetings are penalized as unnecessary only when a section exceeds its target, preventing an early section from taking every physical opportunity merely because all online meetings previously carried a penalty.

### Schedule compactness

- Groups meetings by section and day.
- Adds a penalty for every positive idle gap.
- Adds a larger penalty as the gap grows in 30-minute slots.

### Configuration compliance

- Checks lecture/laboratory split selections per section.
- Checks GEC split patterns per section.
- Checks course delivery-mode preferences.
- Checks anchored schedule preferences when present.

Instructor availability and faculty assignment are intentionally excluded from every quality component.
