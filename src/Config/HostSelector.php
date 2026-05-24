<?php
declare(strict_types=1);

namespace Letts\Config;

use Letts\Exceptions\NoMatchingDugdaleException;

/**
 * Auto-select dugdale candidates by label AND-semantics. Mirrors Go
 * internal/lettsconfig Candidates and PickOne.
 */
final class HostSelector
{
    /**
     * Dugdales matching ALL labels in $match (AND-semantics). When $lane is
     * given, also require the dugdale to declare that lane — auto-select and
     * runOnAll must not target a dugdale that lacks the lane (Go select.go:
     * HasLane && hasAllLabels). $lane=null skips the lane filter (used by
     * dugdales() which lists by labels only).
     *
     * @param list<string> $match
     * @return list<Dugdale>
     */
    public static function candidates(Config $c, array $match, ?string $lane = null): array
    {
        $out = [];
        foreach ($c->dugdales as $d) {
            if ($lane !== null && !$d->hasLane($lane)) {
                continue;
            }
            if (!self::hasAllLabels($d, $match)) {
                continue;
            }
            $out[] = $d;
        }
        return $out;
    }

    /**
     * Pick one candidate. With more than one match, choose RANDOMLY (load
     * distribution) — mirrors Go PickOne, not first-match.
     *
     * @param list<string> $match
     */
    public static function pickOne(Config $c, array $match, ?string $lane = null): Dugdale
    {
        $cands = self::candidates($c, $match, $lane);
        if ($cands === []) {
            throw new NoMatchingDugdaleException(
                'no dugdale matches labels: ' . implode(',', $match) . ($lane !== null ? " (lane: $lane)" : ''),
            );
        }
        if (count($cands) === 1) {
            return $cands[0];
        }
        return $cands[random_int(0, count($cands) - 1)];
    }

    /** @param list<string> $labels */
    private static function hasAllLabels(Dugdale $d, array $labels): bool
    {
        foreach ($labels as $l) {
            if (!$d->hasLabel($l)) {
                return false;
            }
        }
        return true;
    }
}
