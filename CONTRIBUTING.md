# Contributing

Thanks for considering a contribution to **Laravel Deep Agents**.

## Getting started

```bash
git clone https://github.com/twdnhfr/laravel-deepagents
cd laravel-deepagents
composer install
```

## Before you open a PR

Run the full check suite — CI runs the same:

```bash
composer test       # Pest test suite
composer analyse    # PHPStan (max level)
vendor/bin/pint     # code style (Laravel preset)
```

Please:

- Add or update tests for any behaviour change. The runtime is deterministic and
  unit-tested without HTTP (mock the provider/gateway — see `tests/Fixtures/Sdk`);
  end-to-end behaviour against real provider parsing is covered by the spikes in
  `tests/Feature` using `Http::fake`.
- Keep PHPStan and Pint green.
- Match the surrounding code style and the conventions in existing files.

## Architecture

The load-bearing decisions are recorded as ADRs in [`docs/adr/`](docs/adr/) — read
them before changing the loop, the `maxSteps: 0` seam, or anything around tool
execution and human-in-the-loop. The feature roadmap and what is intentionally
out of scope live in [`docs/adoption.md`](docs/adoption.md) and [`TODO.md`](TODO.md).

## Releasing

The changelog is **release-driven** — don't hand-edit `CHANGELOG.md`. To cut a
release:

1. Tag the commit (`vX.Y.Z`) and publish a **GitHub Release** for that tag.
2. Write the release body in changelog style (e.g. `### Added` / `### Fixed`
   sections) — this body *is* the changelog entry.

On publish, the [`Update Changelog`](.github/workflows/update-changelog.yml)
workflow inserts the release body under a `vX.Y.Z` heading in `CHANGELOG.md` and
commits it to `main`. Publishing the release also triggers the Packagist
auto-update webhook, so the new version appears on Packagist automatically.

## Reporting bugs

Open an issue with a minimal reproduction (a failing test is ideal). For security
issues, see [SECURITY.md](SECURITY.md) — do **not** open a public issue.
