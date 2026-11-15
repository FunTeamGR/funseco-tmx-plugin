<?php
namespace Funseco\Tmx;

use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Tmx\Gbx\GbxParser;
use Funseco\Tmx\Gbx\MapGbx;

class TmxService
{
    public function __construct(private AsyncHttpClient $http)
    {
    }

    /**
     * @return PromiseInterface<array{map: MapGbx, body: string}>
     */
    public function downloadTrack(int $tmxId, ?string $exchange = null): PromiseInterface
    {
        $base = $this->baseUrl($exchange);

        return $this->http->get("{$base}/trackgbx/{$tmxId}")
            ->then(function (ResponseInterface $response) use ($tmxId, $exchange) {
                $body = (string) $response->getBody();

                if (empty($body) || str_contains($body, '<html')) {
                    throw new \RuntimeException(
                        'Track ' . $tmxId . ' not found on ' . ($exchange ?? config('tmx.default')) . '.'
                    );
                }

                return [
                    'map'  => GbxParser::parseChallenge($body),
                    'body' => $body,
                ];
            });
    }

    private function baseUrl(?string $exchange = null): string
    {
        $key = $exchange ?? config('tmx.default');

        $url = config("tmx.exchanges.{$key}.base_url")
            ?? throw new \InvalidArgumentException("Unknown TMX exchange: {$key}");

        return rtrim($url, '/');
    }
}
