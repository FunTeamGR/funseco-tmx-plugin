<?php
namespace Funseco\Tmx;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\LastTrackList;
use Funseco\Core\SharedKernel\TrackExchange;
use Funseco\Core\SharedKernel\Values\TmxTrack;

/**
 * Shared argument handling of /tmxinfo and /tmxrecs: [Track_ID|TMX_ID|UID] [section].
 */
class TrackRequest
{
    /** @param \Closure(TmxTrack): void $then */
    public static function run(Player $player, array $args, \Closure $then): void
    {
        $args = array_values(array_filter($args, fn ($a) => $a !== ''));
        $exchange = app(TrackExchange::class);
        $default = strtoupper(config('tmx.default_section', 'TMNF'));
        $arg = $args[0] ?? '';

        // Argument 1 first: anything that is neither a number, a UID nor empty is refused outright.
        $numeric = ctype_digit($arg) && (int) $arg > 0;
        if ($arg !== '' && !$numeric && !preg_match('/^\w{24,27}$/', $arg)) {
            Text::tell($player, Text::get('invalid_id', $arg));
            return;
        }

        // The section argument only means something after a numeric id.
        $section = $default;
        if ($numeric && isset($args[1])) {
            $section = strtoupper($args[1]);
            if (!in_array($section, config('tmx.sections', []), true)) {
                Text::tell($player, Text::get('no_section'));
                return;
            }
        }

        if ($arg === '') {
            $map = CurrentMap::get();
            if (!$map) {
                Text::tell($player, Text::get('not_found', ''));
                return;
            }
            $label = Text::stripColors($map->name);
            $promise = ($cur = $exchange->current()) ? \React\Promise\resolve($cur) : $exchange->forUid($map->uid, $section);
        } elseif ($numeric) {
            $n = (int) $arg;
            $list = app(LastTrackList::class);
            if ($n <= $list->count($player) && ($entry = $list->at($player, $n))) {
                $label = Text::stripColors($entry['name']);
                $cur = $exchange->current();
                $promise = $cur && $cur->uid === $entry['uid'] ? \React\Promise\resolve($cur) : $exchange->forUid($entry['uid'], $section);
            } else {
                $label = (string) $n;
                $promise = $exchange->forId($n, $section);
            }
        } else {
            $label = $arg;
            $cur = $exchange->current();
            $promise = $cur && $cur->uid === $arg ? \React\Promise\resolve($cur) : $exchange->forUid($arg, $section);
        }

        $promise->then(function (?TmxTrack $track) use ($player, $then, $label) {
            $track ? $then($track) : Text::tell($player, Text::get('not_found', $label));
        });
    }
}
