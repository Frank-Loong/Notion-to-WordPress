<?php

/**
 * Action Scheduler is an optional WordPress dependency. These declarations are
 * only scanned by PHPStan; production code still checks function availability.
 */
function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = ''): int {
    return 0;
}

function as_unschedule_action(string $hook, array $args = [], string $group = ''): int {
    return 0;
}
