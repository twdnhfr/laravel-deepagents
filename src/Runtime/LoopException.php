<?php

namespace Twdnhfr\LaravelDeepagents\Runtime;

use RuntimeException;

class LoopException extends RuntimeException
{
    public static function turnLimitExceeded(int $maxTurns): self
    {
        return new self("Agent loop exceeded its turn limit of {$maxTurns}. ".
            'Raise the `maxTurns` limit or investigate a non-terminating tool loop.');
    }

    public static function notSuspended(string $status): self
    {
        return new self("Cannot resume a run with status [{$status}]; only a [suspended] run can be resumed.");
    }

    public static function cannotContinueSuspended(): self
    {
        return new self('Cannot continue a suspended run: it has pending tool calls awaiting approval. '.
            'Call resume() to approve and execute them first.');
    }

    public static function decisionRequiresSuspension(string $status): self
    {
        return new self("Cannot decide on a pending tool call of a run with status [{$status}]; ".
            'only a [suspended] run has calls awaiting approval.');
    }

    public static function unknownPendingCall(string $id): self
    {
        return new self("No pending tool call with id [{$id}] exists on this run.");
    }

    public static function unknownTool(string $name): self
    {
        return new self("The run wants to call tool [{$name}], but no such tool is registered on the loop.");
    }

    public static function unknownMessageRole(string $role): self
    {
        return new self("Cannot hydrate a message with unknown role [{$role}] from the run history.");
    }
}
