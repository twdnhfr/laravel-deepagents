# Resilience — interface sketches (proposal)

> Status: **implemented**, tracking [ADR-0005](adr/0005-resilience-at-the-loop-seam.md).
> The seams (`ModelMiddleware` / `ToolMiddleware`), the graceful halt path, and
> all four shipped batteries have landed. The sketches below match the code,
> though signatures may still be refined.

The loop owns where transient failure is handled. Two contracts wrap the two
fallible calls; everything else is built on them.

| Concern | Seam | Battery | Builder method | State |
|---|---|---|---|---|
| Provider rate-limit / outage | `ModelMiddleware` | `FailoverProviders` | `->provider([…])` | **shipped** |
| Transient model error (timeout, reset) | `ModelMiddleware` | `RetryModelCall` | `->retryModelCall()` | **shipped** |
| Wrong tool arguments | `ToolMiddleware` | `ValidateToolArgs` | `->validateToolArgs()` | **shipped** |
| Transient tool error | `ToolMiddleware` | `RetryTool` | `->retryTools()` | **shipped** |
| No-progress loop | `Hook` (`afterModel`) + halt | `LoopGuard` | `->guardAgainstLoops()` | **shipped** |
| Host-specific (circuit breaker, i18n, …) | the same seams | *you write it* | `->modelMiddleware()` / `->toolMiddleware()` | seam shipped |

The seams (`ModelMiddleware`, `ToolMiddleware`, `ModelCall`, `ToolInvocation`)
and the four batteries live in `src/Runtime/Resilience/`; `LoopGuard` and the
`RunState` halt path live in `src/Runtime/`.

Composition note: passing an array to `->provider()` registers `FailoverProviders`
as the *outermost* model middleware, so a `->retryModelCall()` added alongside it
nests inside — each provider is retried on a transient blip, and only a genuine
`FailoverableException` (rate limit, overload) routes to the next provider. The
two predicates are disjoint by design.

`Hook` (between calls, operates on `RunState`) is unchanged. Middleware wrap a
*call* and control its invocation — that is the new thing.

---

## 1. The contracts

```php
namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Closure;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Tools\Request;

/** The model call a turn is about to make. Middleware may swap provider/model and re-invoke. */
final class ModelCall
{
    /** @param array<int, Message> $messages @param array<int, Tool> $tools */
    public function __construct(
        public readonly TextProvider $provider,
        public readonly string $model,
        public readonly ?string $instructions,
        public readonly array $messages,
        public readonly array $tools,
    ) {}

    public function withProvider(TextProvider $provider, string $model): self
    {
        return new self($provider, $model, $this->instructions, $this->messages, $this->tools);
    }
}

interface ModelMiddleware
{
    /**
     * Run (or skip, retry, re-route) the model call. `$next` performs the actual
     * `generateText(maxSteps: 0)` for the given ModelCall and returns its Step.
     *
     * @param  Closure(ModelCall): Step  $next
     */
    public function handle(ModelCall $call, Closure $next): Step;
}

/** A single tool invocation the loop is about to execute. */
final class ToolInvocation
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public readonly Tool $tool,
        public readonly string $name,
        public readonly array $arguments,
        public readonly Request $request,
    ) {}
}

interface ToolMiddleware
{
    /**
     * Run (or short-circuit) the tool. `$next` calls `$tool->handle($request)`
     * and returns its string result.
     *
     * @param  Closure(ToolInvocation): string  $next
     */
    public function handle(ToolInvocation $call, Closure $next): string;
}
```

Both pipelines compose onion-style around the real call — same model as Laravel
middleware. The library's existing tool-error capture (`Loop.php:191-194`, "hand
the error back to the model as the result") stays as the **innermost** default,
so a thrown tool error is still never fatal even with no middleware configured.

---

## 2. Shipped batteries

### Provider failover — mirrors the SDK

```php
final class FailoverProviders implements ModelMiddleware
{
    /** @param array<int, array{provider: TextProvider, model: string}> $chain */
    public function __construct(private array $chain) {}

    public function handle(ModelCall $call, Closure $next): Step
    {
        $last = null;

        foreach ($this->chain as $target) {
            try {
                return $next($call->withProvider($target['provider'], $target['model']));
            } catch (FailoverableException $e) {   // the SDK's own marker
                $last = $e;
                event(new AgentFailedOver(...));    // the SDK's own event
            }
        }

        throw $last; // every provider failed over
    }
}
```

Failover catches **only** `FailoverableException` — the same contract the SDK
honours in `Promptable`. We do not invent a parallel notion of "failoverable".

### Retry with backoff — for transient, non-failoverable errors

```php
final class RetryModelCall implements ModelMiddleware
{
    /**
     * @param  Closure(\Throwable): bool  $retryable  default: connection/timeout errors, NOT FailoverableException
     * @param  Closure(int): void         $sleep      backoff; injectable so tests don't actually wait
     */
    public function __construct(
        private int $times = 2,
        private ?Closure $retryable = null,
        private ?Closure $sleep = null,
    ) {}

    public function handle(ModelCall $call, Closure $next): Step
    {
        $attempt = 0;

        while (true) {
            try {
                return $next($call);
            } catch (\Throwable $e) {
                $attempt++;

                if ($attempt > $this->times || ! $this->isRetryable($e)) {
                    throw $e;
                }

                ($this->sleep ?? $this->defaultBackoff(...))($attempt);
            }
        }
    }
}
```

> **Composition.** Retry wraps a single provider; failover wraps the chain. So
> the default order is `FailoverProviders( RetryModelCall( actualCall ) )`:
> retry a transient blip on the current provider, and only fail over on a
> genuine `FailoverableException`. The two predicates are deliberately disjoint —
> a rate limit is failoverable (→ switch provider), a timeout is retryable
> (→ try the same provider again).

### Argument validation — provider-agnostic self-correction

```php
final class ValidateToolArgs implements ToolMiddleware
{
    public function handle(ToolInvocation $call, Closure $next): string
    {
        // Compare $call->arguments against $call->tool->schema(...): missing
        // required keys / unknown keys -> return a structured message INSTEAD of
        // calling $next, so the model fixes the call on its next turn.
        if ($error = $this->check($call)) {
            return $error;
        }

        return $next($call);
    }
}
```

Uses only `Tool::schema()`, so it works for any tool. Messages are neutral
English; a host that wants another language ships its own `ToolMiddleware`.

### Tool retry

```php
final class RetryTool implements ToolMiddleware
{
    /** @param Closure(\Throwable): bool $retryable */
    public function __construct(
        private int $times = 3,
        private ?Closure $retryable = null,
        private ?Closure $sleep = null,
    ) {}

    public function handle(ToolInvocation $call, Closure $next): string { /* retry loop, as above */ }
}
```

### Loop guard — stop a no-progress run (a `Hook`, not middleware)

No-progress detection reads `RunState` *between* turns, so it is a `Hook`, not a
call wrapper. It needs the new halt path to stop cleanly.

```php
final class LoopGuard extends LoopHook
{
    public function __construct(private int $repeats = 3) {}

    public function afterModel(RunState $state): void
    {
        // Look at the trailing assistant turns' tool-call signatures
        // (name + arguments, which the loop already normalises). If the last
        // $repeats turns are an identical single call, the agent is stuck.
        if ($this->repeatedCallStreak($state) >= $this->repeats) {
            $state->halt("No progress: the same tool call repeated {$this->repeats} times.");
        }
    }
}
```

---

## 3. Structural changes this requires

### `RunState`: a graceful terminal status

```php
// src/Runtime/RunState.php
public const STATUS_HALTED = 'halted';

public ?string $haltReason = null;   // serialized alongside status

public function isHalted(): bool { return $this->status === self::STATUS_HALTED; }

public function halt(string $reason): void
{
    $this->status = self::STATUS_HALTED;
    $this->haltReason = $reason;
}
```

`haltReason` joins `jsonSerialize()` / `fromArray()` so a halted run round-trips
like any other. `halted` is terminal and distinct from `done`: the caller can
tell "the agent finished" from "the loop stopped it".

### `Loop`: honour a halt, and run the pipelines

```php
// src/Runtime/Loop.php — advance(): re-check after the turn so an afterModel
// hook (e.g. LoopGuard) can stop the run before its tool calls execute.
$step = $this->turn($state);

if (! $state->isRunning()) {
    return $state;            // halted (or otherwise stopped) by a hook
}
```

```php
// turn(): the generateText call becomes the tail of the ModelMiddleware pipeline.
$step = $this->runModelPipeline(
    new ModelCall($this->provider, $this->model, $state->instructions, $this->hydrate($state->history), $this->tools),
    fn (ModelCall $c) => $c->provider->textGateway()->generateText(
        $c->provider, $c->model, $c->instructions, $c->messages, $c->tools, null,
        new TextGenerationOptions(maxSteps: 0),
    )->steps->first(),
);
```

```php
// executeCalls(): the $tool->handle() call becomes the tail of the ToolMiddleware
// pipeline; the existing try/catch stays wrapped around the whole pipeline so a
// thrown error still becomes a tool result rather than crashing the run.
$result = $this->runToolPipeline(
    new ToolInvocation($tool, $call['name'], $call['arguments'], new Request($call['arguments'])),
    fn (ToolInvocation $i) => (string) $i->tool->handle($i->request),
);
```

The `Loop` constructor gains `array $modelMiddleware = []` and
`array $toolMiddleware = []`, alongside the existing `$hooks`. None of it is
serialized — it is runtime config, rebuilt per process, exactly like
`$provider` / `$tools` / `$hooks` today.

---

## 4. Builder surface (`DeepAgent`)

```php
DeepAgent::make()
    // failover: an array makes provider() an ordered chain (name => model,
    // null = provider default), mirroring the SDK's failover config shape.
    ->provider(['anthropic' => 'claude-sonnet-4-5', 'openai' => null])

    // batteries
    ->retryModelCall(times: 2)                       // transient, non-failoverable
    ->validateToolArgs()                             // schema self-correction
    ->retryTools(times: 3, retryable: fn ($e) => $e instanceof ConnectionException)
    ->guardAgainstLoops(repeats: 3)                  // adds the LoopGuard hook

    // low-level escape hatch — host-specific policy plugs in here
    ->modelMiddleware($circuitBreaker)               // e.g. wraps the call in your breaker
    ->toolMiddleware($yourLocalizedValidator)

    ->run('…');
```

`provider()` takes a single provider or, given an array, an ordered failover
chain — one method, a union type, the way `laravel/ai` itself does it. The
convenience methods (`retryModelCall`,
`validateToolArgs`, `retryTools`, `guardAgainstLoops`) just push the
corresponding shipped battery onto the same middleware/hook arrays that
`modelMiddleware()` / `toolMiddleware()` / `hook()` expose directly — so the
batteries have no privileged path; they prove the seam.

---

## 5. Explicitly out of scope

Kept out so the library stays general (a host supplies these via the seam):

- **Circuit breaker with cross-request state** — needs a cache/store and a
  cooldown policy; that is application state, not loop state.
- **Provider-specific rate-limit header parsing** (`x-ratelimit-*`, `Retry-After`
  → cooldown seconds) — provider-specific, belongs in the host's `ModelMiddleware`.
- **Fallback-provider names / model choices** — host config, passed into
  `provider([…])`.
- **Localized / branded error messages** — host's tool middleware.
- **Token / cost budgets** — a `Hook` that halts; can ship later, not part of
  this proposal.
