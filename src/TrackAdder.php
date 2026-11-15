<?php
namespace Funseco\Tmx;

use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\MatchSettings;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\SharedKernel\Enums\MessageType;
use Funseco\Core\SharedKernel\Events\TrackAdded;
use Funseco\Core\SharedKernel\Values\TrackAddResult;
use Funseco\Tmx\Gbx\MapGbx;
use Funseco\Tmx\Text;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/**
 * Download a TMX track, save it under Challenges/TMX, insert it after the current track,
 * persist the track list and fire TrackAdded (the jukebox queues it). Used by /admin add
 * and by TrackAddRequested (a passed player /add vote).
 */
class TrackAdder
{
    /**
     * @param ?bool $queue true/false forces the jukebox to queue the map or not; null = the jukebox's own rule
     * @param bool|string $report who hears errors in chat: true = the requester, false = nobody (the
     *        caller reads the result), a login = that player (for a synthetic requester such as the /rtmx bot)
     * @return PromiseInterface<TrackAddResult> always resolves, never rejects
     */
    public function add(Player $requester, int $tmxId, ?string $exchange = null, bool|string $report = true, ?bool $queue = null): PromiseInterface
    {
        $fail = function (string $message) use ($requester, $report): TrackAddResult {
            if ($report !== false) {
                Chat::sendMessageToLogin(is_string($report) ? $report : $requester->login, $message, MessageType::Error);
            }

            return TrackAddResult::failed($message);
        };

        $tracksDir = ServerInfo::getTracksDirectory();
        if (!$tracksDir) {
            return resolve($fail('Tracks directory unavailable.'));
        }

        try {
            $download = app(TmxService::class)->downloadTrack($tmxId, $exchange);
        } catch (\Throwable $e) {
            return resolve($fail($e->getMessage()));
        }

        // Download runs on the event loop; the controller keeps serving players meanwhile.
        return $download
            ->then(fn (array $result): PromiseInterface => $this->install($requester, $result['map'], $result['body'], 'TMX', $report, $queue))
            ->then(null, fn (\Throwable $e) => $fail($e->getMessage()));
    }

    /**
     * Save a map file under Challenges/<dir>, insert it after the current track, persist the track list and
     * fire TrackAdded. Shared by the TMX download and by files uploaded through the web app.
     *
     * @return PromiseInterface<TrackAddResult> always resolves
     */
    public function install(Player $requester, MapGbx $gbx, string $body, string $dir, bool|string $report = true, ?bool $queue = null): PromiseInterface
    {
        $fail = function (string $message) use ($requester, $report): TrackAddResult {
            if ($report !== false) {
                Chat::sendMessageToLogin(is_string($report) ? $report : $requester->login, $message, MessageType::Error);
            }

            return TrackAddResult::failed($message);
        };

        $tracksDir = ServerInfo::getTracksDirectory();
        if (!$tracksDir) {
            return resolve($fail('Tracks directory unavailable.'));
        }

        // ManiaPlanet (TM2) maps carry a maptype; a TMNF/TMUF server cannot load them and just says no.
        if (str_contains(substr($body, 0, 20000), 'maptype=')) {
            return resolve($fail('That is a ManiaPlanet (TM2) map; this server needs a TMNF/TMUF map.'));
        }

        $filename = "Challenges/{$dir}/{$gbx->safeFilename()}.gbx";
        $fullPath = $tracksDir.'/'.$filename;
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        file_put_contents($fullPath, $body);

        $deferred = new Deferred();
        \Server::query()->InsertChallenge($filename, function ($result) use ($requester, $gbx, $filename, $fail, $report, $deferred, $queue) {
            if (!($result[0] ?? false)) {
                $deferred->resolve($fail('Server rejected the map — wrong environment?'));
                return;
            }
            Chat::sendMessage('{#highlite}'.Text::stripColors($gbx->name).'{#message} by {#highlite}'.Text::stripColors($gbx->author).'{#message} added to the track list.');
            // Persist the new track list so the map is still there after a server restart.
            if (config('server.matchsettings')) {
                MatchSettings::save()->catch(fn (\Throwable $e) => $report === false ? null : Chat::sendMessageToLogin(
                    is_string($report) ? $report : $requester->login, 'Map added, but the track list could not be saved: '.$e->getMessage(), MessageType::Error
                ));
            }
            event(new TrackAdded(self::map($gbx, $filename), $requester, 'tmx', $queue));
            $deferred->resolve(new TrackAddResult(true, $gbx->name, $gbx->author));
        });

        return $deferred->promise();
    }

    private static function map(MapGbx $gbx, string $filename): Map
    {
        $map = Map::firstOrNew(['uid' => $gbx->uid]);
        $map->fill([
            'name' => $gbx->name, 'author' => $gbx->author, 'environment' => $gbx->environment,
            'mood' => $gbx->mood, 'file_name' => $filename,
            'bronze_time' => $gbx->bronzeTime, 'silver_time' => $gbx->silverTime,
            'gold_time' => $gbx->goldTime, 'author_time' => $gbx->authorTime,
            'copper_price' => $gbx->price, 'lap_count' => $gbx->nblaps,
        ])->save();

        return $map;
    }
}
