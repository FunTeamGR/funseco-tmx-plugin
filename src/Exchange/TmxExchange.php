<?php
namespace Funseco\Tmx\Exchange;

use React\Promise\PromiseInterface;
use Funseco\Core\Models\Map;
use Funseco\Core\SharedKernel\Events\TrackExchangeInfoLoaded;
use Funseco\Core\SharedKernel\TrackExchange;
use Funseco\Core\SharedKernel\Values\TmxTrack;
use Funseco\Tmx\Text;
use function React\Promise\resolve;

/**
 * The TrackExchange implementation. Lookups are cached for the current map only (successes;
 * a failure is retried on the next ask) and at most one request per key is in flight.
 */
class TmxExchange implements TrackExchange
{
    private ?TmxTrack $current = null;
    private ?string $currentUid = null;
    /** @var array<string, PromiseInterface> */
    private array $lookups = [];

    public function __construct(private TmxApi $api)
    {
    }

    public function current(): ?TmxTrack
    {
        return $this->current;
    }

    public function forUid(string $uid, ?string $section = null): PromiseInterface
    {
        return $this->lookup($section, 'uid:'.$uid, ['uid' => $uid]);
    }

    public function forId(int $id, ?string $section = null): PromiseInterface
    {
        return $this->lookup($section, 'id:'.$id, ['id' => $id]);
    }

    /** New map: forget the old one, look the track up and publish the result. */
    public function mapStarted(Map $map): void
    {
        $this->lookups = [];
        $this->current = null;
        $this->currentUid = $map->uid;

        $sections = config('tmx.lookup_sections.'.$map->environment) ?? config('tmx.lookup_sections.*', ['TMU']);
        $try = function (array $left) use (&$try, $map): PromiseInterface {
            if (!$left) {
                return resolve(null);
            }
            $section = array_shift($left);

            return $this->forUid($map->uid, $section)->then(fn (?TmxTrack $track) => $track ?? $try($left));
        };

        $try($sections)->then(function (?TmxTrack $track) use ($map) {
            if ($this->currentUid !== $map->uid) {
                return;   // the map changed while TMX was answering
            }
            $this->current = $track;
            event(new TrackExchangeInfoLoaded($map->uid, $track));
            $this->announceRecord($track);
        });
    }

    private function announceRecord(?TmxTrack $track): void
    {
        $mode = (int) config('tmx.show_tmxrec', 0);
        if ($mode < 1 || !$track || !($wr = $track->worldRecord())) {
            return;
        }
        Text::broadcast(Text::get('tmxrec', $track->isStunts() ? (string) $wr->score : Text::time($wr->time), Text::stripColors($wr->name)));
    }

    private function lookup(?string $section, string $key, array $by): PromiseInterface
    {
        $section = strtoupper($section ?? config('tmx.default_section', 'TMNF'));
        $key = $section.':'.$key;

        if (isset($this->lookups[$key])) {
            return $this->lookups[$key];
        }
        $promise = $this->lookups[$key] = $this->api->track($section, $by)
            ->then(fn (?TmxTrack $track) => $track
                ? $this->api->records($section, $track->id)->then(fn (array $records) => new TmxTrack(...[...get_object_vars($track), 'records' => $records]))
                : null);
        // only successes stay cached; a miss or TMX being down is asked again next time
        $promise->then(function (?TmxTrack $track) use ($key, $promise) {
            if (!$track && ($this->lookups[$key] ?? null) === $promise) {
                unset($this->lookups[$key]);
            }
        });

        return $promise;
    }
}
