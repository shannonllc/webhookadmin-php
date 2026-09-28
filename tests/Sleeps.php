<?php

declare(strict_types=1);

namespace WebhookAdmin\Tests;

/** Records the waits between retries instead of sleeping. */
final class Sleeps
{
    /** @var list<float> */
    public array $waits = [];

    public function __invoke(float $s): void
    {
        $this->waits[] = $s;
    }
}
