<?php

namespace Odnavi\Orm\Service\Support;

use Odnavi\Core\Contract\Profiler;

/** Профайлер по умолчанию: ничего не делает. */
final class NullProfiler implements Profiler
{
    public function start(string $label, array $context = []): void {}

    public function stop(): void {}
}
