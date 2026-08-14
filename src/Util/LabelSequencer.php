<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Util;

/**
 * Increments a trailing numeric run in a label string, preserving prefix
 * and zero-padding width — "cheztac-2026.001.0001" -> "...0002",
 * "rhs-001" -> "rhs-002". Matches how physical scanners let you set a
 * starting number/pattern for a batch and auto-increment from there.
 */
final class LabelSequencer
{
    public static function next(string $label): string
    {
        if (preg_match('/^(.*?)(\d+)$/', $label, $m) === 1) {
            $next = (string) ((int) $m[2] + 1);

            return $m[1] . str_pad($next, \strlen($m[2]), '0', STR_PAD_LEFT);
        }

        return $label . '-2';
    }
}
