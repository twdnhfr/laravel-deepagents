# ADR-0003: Depend on `laravel/ai` `^0.7`, not `^1.0`

- **Status:** Accepted
- **Date:** 2026-05-29
- **Deciders:** twdnhfr

## Context

The package was conceived as a harness on top of "the Laravel AI SDK 1.x". When
wiring the dependency, reality differed from the intent:

- `laravel/ai` has **no 1.0 release tagged on Packagist** — the latest published
  tag is `v0.7.2`. Requiring `^1.0` fails to resolve
  (`Root composer.json requires laravel/ai ^1.0 ... it does not match the constraint`).
- The `master` branch *is* the 1.x line (its `composer.json` declares a
  `dev-master → 1.x-dev` branch alias), but Packagist still serves `dev-master`
  as `0.x-dev`. Pulling the real 1.x code requires a VCS repository entry plus
  GitHub authentication at install time — friction for every contributor and CI.
- Crucially, the code we depend on is **identical** between `master` and the
  published `v0.7.2`: the `maxSteps` resolution
  (`TextGenerationOptions::forAgent`, `#[MaxSteps]`), the per-provider
  `processResponse()` guards, and the public `TextProvider::textGateway()` seam
  all exist as-is in `v0.7.2`. The decisions in
  [ADR-0001](0001-own-the-agent-loop.md) and
  [ADR-0002](0002-maxsteps-zero-single-turn-seam.md) hold against it.

## Decision

Depend on **`laravel/ai: ^0.7`** for now. Build, test, and release against the
published line. Move the constraint to `^1.0` once `laravel/ai` tags a 1.0
release on Packagist — expected to be a small change, since we already target
the 1.x-bound code.

## Consequences

**Positive**

- `composer install` works with no VCS entry, no GitHub token, no
  `minimum-stability: dev` gymnastics — frictionless for contributors and CI.
- The package is installable and testable today against a stable, published
  dependency.

**Negative / risks**

- `^0.7` is a pre-1.0 line: minor releases may carry breaking changes, and the
  internal seams we rely on ([ADR-0002](0002-maxsteps-zero-single-turn-seam.md))
  could shift before 1.0 stabilizes them. The spike tests are the early-warning
  system.
- The package README/docs must not over-promise "1.x" until the constraint is
  actually bumped.

## Follow-up

- [ ] Bump to `^1.0` when `laravel/ai` 1.0 is tagged on Packagist; re-run the
      full suite (esp. the spikes) against it before release.

## References

- `composer.json` (`"laravel/ai": "^0.7"`)
- `TODO.md` (§1 note on the `^0.7` → `^1.0` move)
