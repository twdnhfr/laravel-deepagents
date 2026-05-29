# Security Policy

## Supported versions

This package is in early development (`0.x`). Only the latest release receives
fixes.

## Reporting a vulnerability

Please report security vulnerabilities privately by email to
**tobias@wdnhfr.de** — do not open a public issue.

Include enough detail to reproduce (affected version, a minimal example, and the
impact). You'll get an acknowledgement as soon as possible, and a fix or
mitigation will be coordinated before any public disclosure.

## Security model

Laravel Deep Agents follows a **"trust the LLM"** model, like the project that
inspired it: the agent can do whatever its tools allow. Enforce boundaries at the
**tool, backend and approval** layers — not by expecting the model to self-police.

- Default to the side-effect-free `StateBackend`; real disk (`FilesystemBackend`)
  and any tool with side effects are explicit opt-in.
- Gate destructive tools behind `requireApproval()` (human-in-the-loop).
- When persisting a suspended `RunState` for later resumption, store it
  **server-side** and hand the client only an opaque, single-use token — never
  trust a client-supplied run state, which would let a caller execute arbitrary
  tool calls on resume.
