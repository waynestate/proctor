<?php

namespace App\Services;

/**
 * An embeddings service: vector() here is math on the app's own class,
 * not a schema Blueprint column.
 */
class Embedder
{
    public function vector(array $a): array
    {
        return array_map(static fn(int $x): int => $x * 2, $a);
    }

    public static function instant(): string
    {
        return 'instant';
    }

    public function embed(array $a): array
    {
        return $this->vector($a);
    }

    public function stamp(): string
    {
        return self::instant();
    }
}
