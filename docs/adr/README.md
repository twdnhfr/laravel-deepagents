# Architecture Decision Records

Short, immutable records of significant architecture decisions and *why* they
were made — in the [Michael Nygard format](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions).

A decision doesn't get edited once accepted; if it changes, a new ADR supersedes
the old one (which is marked `Superseded by ADR-XXXX`).

| ADR | Title | Status |
|-----|-------|--------|
| [0001](0001-own-the-agent-loop.md) | Own the agent loop (Path B) instead of the SDK's internal loop | Accepted |
| [0002](0002-maxsteps-zero-single-turn-seam.md) | Use `maxSteps: 0` as the uniform single-turn seam | Accepted |
| [0003](0003-depend-on-laravel-ai-0.7.md) | Depend on `laravel/ai` `^0.7`, not `^1.0` | Accepted |
| [0004](0004-no-token-streaming-through-the-loop.md) | No token streaming through the owned loop (for now) | Accepted |
| [0005](0005-resilience-at-the-loop-seam.md) | Resilience lives at the loop seam, as around-call middleware | Accepted |
