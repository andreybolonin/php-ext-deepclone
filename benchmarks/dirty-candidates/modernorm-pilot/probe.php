<?php

declare(strict_types=1);

final class DirtyProbe
{
    public int $count;
    public array $payload;
    public DateTimeImmutable $date;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fields = ['count', 'payload', 'date'];
$a = new DirtyProbe();
$a->count = 1;
$a->payload = ['nested' => ['x' => 7]];
$a->date = new DateTimeImmutable('2026-11-26');
$snapshots = [spl_object_id($a) => (array) $a];
check(deepclone_dirty_candidates(DirtyProbe::class, $fields, [$a], $snapshots) === [], 'Unchanged values flagged');
$a->payload['nested']['x'] = 8;
check(deepclone_dirty_candidates(DirtyProbe::class, $fields, [$a], $snapshots) === [$a], 'Changed nested JSON skipped');
$a->payload['nested']['x'] = 7;
$a->date = clone $a->date;
check(deepclone_dirty_candidates(DirtyProbe::class, $fields, [$a], $snapshots) === [$a], 'Replacement date identity lost');

$ghost = (new ReflectionClass(DirtyProbe::class))->newLazyGhost(static function (): void {
    throw new RuntimeException('Probe initialized a lazy object');
});
check(deepclone_dirty_candidates(DirtyProbe::class, $fields, [$ghost], $snapshots) === [$ghost], 'Lazy object skipped');
check(deepclone_dirty_candidates(DirtyProbe::class, $fields, [new DirtyProbe()], []) !== [], 'Missing snapshot skipped');

final class DirtyHook
{
    public int $value = 1 {
        get => $this->value;
    }
}

try {
    deepclone_dirty_candidates(DirtyHook::class, ['value'], [], []);
    throw new RuntimeException('Hook was accepted in the native plan');
} catch (ValueError) {
}

echo "PASS scalar and nested array equality, object identity, lazy fallback, missing snapshots and hook rejection\n";
