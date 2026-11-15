<?php
namespace Funseco\Tmx\Exchange;

use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Values\TmxRecord;
use Funseco\Core\SharedKernel\Values\TmxTrack;
use function React\Promise\resolve;

/**
 * Client of the TMNF-X / TMUF-X JSON API (/api/tracks, /api/replays). Every method returns a
 * promise that never rejects: TMX down, a bad body or an unknown track all resolve to null / [].
 */
class TmxApi
{
    private const TRACK_FIELDS = 'TrackId,TrackName,UId,Authors[],UploadedAt,UpdatedAt,PrimaryType,Environment,Mood,Style,Routes,'
        .'Difficulty,Length,Awards,AuthorTime,TrackValue,AuthorComments,WRReplay.ReplayId,WRReplay.ReplayTime,WRReplay.User.Name';
    private const RECORD_FIELDS = 'ReplayId,User.Name,ReplayTime,ReplayScore,Position,ReplayAt,TrackAt,Score';
    private const SEARCH_FIELDS = 'TrackId,TrackName,UId,Authors[],Environment';

    public function __construct(private AsyncHttpClient $http)
    {
    }

    /** @return array{name: string, section: string, base_url: string, json_api: bool, image_url: ?string}|null */
    public static function exchange(string $section): ?array
    {
        foreach (config('tmx.exchanges', []) as $key => $exchange) {
            if (strcasecmp($exchange['section'] ?? $key, $section) === 0) {
                return [
                    'name' => $exchange['name'] ?? $key,
                    'section' => strtoupper($exchange['section'] ?? $key),
                    'base_url' => rtrim($exchange['base_url'], '/'),
                    'json_api' => (bool) ($exchange['json_api'] ?? false),
                    'legacy_api' => (bool) ($exchange['legacy_api'] ?? false),
                    'image_url' => $exchange['image_url'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * One track (without records) by UID or TMX id.
     *
     * @param array{uid?: string, id?: int} $by
     * @return PromiseInterface<?TmxTrack>
     */
    public function track(string $section, array $by): PromiseInterface
    {
        if ($ex = self::exchange($section)) {
            if ($ex['legacy_api']) {
                return $this->legacyTrack($ex, $by);
            }
        }
        if (!$ex || !$ex['json_api']) {
            return resolve(null);
        }
        $query = isset($by['uid']) ? 'uid='.rawurlencode($by['uid']) : 'id='.(int) $by['id'];

        return $this->json($ex['base_url'].'/api/tracks?'.$query.'&fields='.self::enc(self::TRACK_FIELDS))
            ->then(fn ($json) => isset($json['Results'][0]) ? $this->toTrack($json['Results'][0], $ex) : null);
    }

    /**
     * Top-10 records of a TMX track.
     *
     * @return PromiseInterface<TmxRecord[]>
     */
    public function records(string $section, int $trackId): PromiseInterface
    {
        if (($ex = self::exchange($section)) && $ex['legacy_api']) {
            return $this->legacyRecords($ex, $trackId);
        }
        if (!($ex = self::exchange($section)) || !$ex['json_api']) {
            return resolve([]);
        }

        return $this->json($ex['base_url'].'/api/replays?trackId='.$trackId.'&best=1&count=10&fields='.self::enc(self::RECORD_FIELDS))
            ->then(function ($json) use ($ex) {
                $records = [];
                foreach (array_slice($json['Results'] ?? [], 0, 10) as $i => $row) {
                    $records[] = new TmxRecord(
                        rank: $i + 1,
                        replayId: (int) ($row['ReplayId'] ?? 0),
                        name: (string) ($row['User']['Name'] ?? ''),
                        time: (int) ($row['ReplayTime'] ?? 0),
                        score: (int) ($row['ReplayScore'] ?? 0),
                        replayAt: $row['ReplayAt'] ?? null,
                        trackAt: $row['TrackAt'] ?? null,
                        replayUrl: ($row['ReplayId'] ?? 0) > 0 ? $ex['base_url'].'/recordgbx/'.(int) $row['ReplayId'] : null,
                    );
                }

                return $records;
            });
    }

    /**
     * Search (or, with all filters empty and $recent, the most recent tracks). Pages are followed
     * with the API's `after` cursor until $max rows are collected.
     *
     * @return PromiseInterface<array<int, array{id: int, name: string, author: string, env: string}>>
     */
    public function search(string $section, string $name, string $author, ?string $env, int $max, bool $recent = false): PromiseInterface
    {
        if (!($ex = self::exchange($section)) || !$ex['json_api']) {
            return resolve([]);
        }
        $pageSize = max(1, min($max, (int) config('tmx.xlist.page_size', 100)));
        $base = $ex['base_url'].'/api/tracks?count='.$pageSize.'&fields='.self::enc(self::SEARCH_FIELDS);
        if ($recent) {
            $base .= '&order1=0';
        }
        if ($name !== '') {
            $base .= '&name='.rawurlencode($name);
        }
        if ($author !== '') {
            $base .= '&author='.rawurlencode($author);
        }
        if ($env !== null && $env !== '' && ($code = array_search(strtolower($env), array_map('strtolower', config('tmx.enums.environment', [])), true)) !== false) {
            $base .= '&environment='.$code;
        }

        $rows = [];
        $step = function (?int $after) use (&$step, &$rows, $base, $max, $ex, $recent): PromiseInterface {
            return $this->json($base.($after ? '&after='.$after : ''))->then(function ($json) use (&$step, &$rows, $max, $ex, $after, $recent) {
                $results = $json['Results'] ?? [];
                foreach ($results as $row) {
                    $rows[] = [
                        'id' => (int) ($row['TrackId'] ?? 0),
                        'name' => (string) ($row['TrackName'] ?? ''),
                        'author' => (string) ($row['Authors'][0]['User']['Name'] ?? ''),
                        'env' => self::enumName('environment', $row['Environment'] ?? null),
                    ];
                }
                $last = $results ? (int) end($results)['TrackId'] : 0;
                if ($recent || empty($json['More']) || count($rows) >= $max || $last === 0 || $last === $after) {
                    return array_slice($rows, 0, $max);
                }

                return $step($last);
            });
        };

        return $step(null);
    }

    /**
     * Old TMX host (TMS/TMN): apiget.aspx answers one tab-separated line per row.
     * Info columns: id, name, userid, author, uploaded, updated, visible, type, environment,
     * mood, style, routes, length, difficulty, lbrating, game, comment, awards, comments, customimage, wr replay id.
     * The column order is from memory of the old API, not verified against the (retired) hosts.
     */
    private function legacyTrack(array $ex, array $by): PromiseInterface
    {
        $query = isset($by['uid']) ? 'uid='.rawurlencode($by['uid']) : 'id='.(int) $by['id'];

        return $this->text($ex['base_url'].'/apiget.aspx?action=apitrackinfo&'.$query)->then(function (?string $body) use ($ex) {
            $line = trim(preg_split('/\r?\n/', (string) $body)[0]);
            $c = explode("\t", $line);
            if (count($c) < 15 || (int) $c[0] <= 0 || $c[1] === '') {
                return null;
            }
            $id = (int) $c[0];
            $wr = (int) ($c[20] ?? 0);
            $image = $ex['base_url'].'/get.aspx?action=trackscreen&id='.$id;

            return new TmxTrack(
                id: $id,
                uid: $by['uid'] ?? '',
                name: $c[1],
                author: $c[3],
                uploadedAt: self::legacyDate($c[4]),
                updatedAt: self::legacyDate($c[5]),
                type: $c[7],
                style: $c[10],
                environment: $c[8],
                mood: $c[9],
                difficulty: $c[13],
                routes: $c[11],
                length: $c[12],
                awards: (int) ($c[17] ?? 0),
                lbRating: (int) $c[14] === 0 ? 'Classic!' : $c[14],
                section: $ex['section'],
                authorComment: str_replace(['<br/>', '<br>'], "\n", $c[16] ?? ''),
                authorTime: 0,
                pageUrl: $ex['base_url'].'/trackshow/'.$id,
                imageUrl: $image,
                downloadUrl: $ex['base_url'].'/get.aspx?action=trackgbx&id='.$id,
                replayUrl: $wr > 0 ? $ex['base_url'].'/get.aspx?action=recordgbx&id='.$wr : null,
            );
        });
    }

    /** Record columns: replay id, user id, name, time, replay date, track date, approved, score, expires, lockspan. */
    private function legacyRecords(array $ex, int $trackId): PromiseInterface
    {
        return $this->text($ex['base_url'].'/apiget.aspx?action=apitrackrecords&id='.$trackId)->then(function (?string $body) use ($ex) {
            $records = [];
            foreach (preg_split('/\r?\n/', (string) $body, -1, PREG_SPLIT_NO_EMPTY) as $line) {
                $c = explode("\t", $line);
                if (count($c) < 4 || count($records) >= 10) {
                    continue;
                }
                $replay = (int) $c[0];
                $records[] = new TmxRecord(
                    rank: count($records) + 1,
                    replayId: $replay,
                    name: $c[2],
                    time: (int) $c[3],
                    score: (int) ($c[7] ?? 0),
                    replayAt: self::legacyDate($c[4] ?? ''),
                    trackAt: self::legacyDate($c[5] ?? ''),
                    replayUrl: $replay > 0 ? $ex['base_url'].'/get.aspx?action=recordgbx&id='.$replay : null,
                );
            }

            return $records;
        });
    }

    /** "4/11/2008 9:27:57 PM" → "2008-04-11T21:27:57"; null when it is not a date. */
    private static function legacyDate(string $text): ?string
    {
        $t = $text === '' ? false : strtotime($text.' UTC');

        return $t ? gmdate('Y-m-d\TH:i:s', $t) : null;
    }

    /** GET → body text, or null on any failure. */
    private function text(string $url): PromiseInterface
    {
        return $this->http->get($url, ['User-Agent' => 'Funseco'])->then(
            fn (ResponseInterface $response) => (string) $response->getBody(),
            function (\Throwable $e) use ($url) {
                Log::warning('[Tmx] request failed: '.$url.' - '.$e->getMessage());

                return null;
            },
        );
    }

    /** GET → decoded JSON array, or null on any failure. */
    private function json(string $url): PromiseInterface
    {
        return $this->http->get($url, ['Accept' => 'application/json', 'User-Agent' => 'Funseco'])->then(
            function (ResponseInterface $response) {
                $json = json_decode((string) $response->getBody(), true);

                return is_array($json) ? $json : null;
            },
            function (\Throwable $e) use ($url) {
                Log::warning('[Tmx] request failed: '.$url.' - '.$e->getMessage());

                return null;
            },
        );
    }

    private function toTrack(array $row, array $ex): TmxTrack
    {
        $id = (int) ($row['TrackId'] ?? 0);
        $time = (int) ($row['AuthorTime'] ?? 0);
        $wr = (int) ($row['WRReplay']['ReplayId'] ?? 0);
        $image = $ex['image_url'] ?? ($ex['base_url'].'/trackshow/{id}/image/1');

        return new TmxTrack(
            id: $id,
            uid: (string) ($row['UId'] ?? ''),
            name: (string) ($row['TrackName'] ?? ''),
            author: (string) ($row['Authors'][0]['User']['Name'] ?? ''),
            uploadedAt: $row['UploadedAt'] ?? null,
            updatedAt: $row['UpdatedAt'] ?? null,
            type: self::enumName('type', $row['PrimaryType'] ?? null),
            style: self::enumName('style', $row['Style'] ?? null),
            environment: self::enumName('environment', $row['Environment'] ?? null),
            mood: self::enumName('mood', $row['Mood'] ?? null),
            difficulty: self::enumName('difficulty', $row['Difficulty'] ?? null),
            routes: self::enumName('routes', $row['Routes'] ?? null),
            length: self::length($time),
            awards: (int) ($row['Awards'] ?? 0),
            lbRating: (int) ($row['TrackValue'] ?? 0) === 0 ? 'Classic!' : (string) $row['TrackValue'],
            section: $ex['section'],
            authorComment: (string) ($row['AuthorComments'] ?? ''),
            authorTime: $time,
            pageUrl: $ex['base_url'].'/trackshow/'.$id,
            imageUrl: str_replace('{id}', (string) $id, $image),
            downloadUrl: $ex['base_url'].'/trackgbx/'.$id,
            replayUrl: $wr > 0 ? $ex['base_url'].'/recordgbx/'.$wr : null,
        );
    }

    /** Author time as TMX shows it: "24s", "1m 5s". */
    public static function length(int $ms): string
    {
        $s = intdiv($ms, 1000);

        return $s >= 60 ? intdiv($s, 60).'m '.($s % 60).'s' : $s.'s';
    }

    /** TMX enum code → display name from config('tmx.enums'); unknown codes stay numbers. */
    public static function enumName(string $field, mixed $code): string
    {
        if ($code === null) {
            return '';
        }

        return (string) (config("tmx.enums.$field.$code") ?? $code);
    }

    private static function enc(string $fields): string
    {
        return str_replace(['[', ']'], ['%5B', '%5D'], $fields);
    }
}
