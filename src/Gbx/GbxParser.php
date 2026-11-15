<?php
namespace Funseco\Tmx\Gbx;

class GbxParser
{
    public static function parseChallenge(string $data): MapGbx
    {
        $start = strpos($data, '<header ');
        if ($start === false) {
            throw new \RuntimeException('GBX file contains no challenge header XML.');
        }

        $end = strpos($data, '</header>', $start);
        if ($end === false) {
            throw new \RuntimeException('GBX challenge header XML is malformed.');
        }

        $xml = substr($data, $start, $end - $start + strlen('</header>'));

        $doc = new \SimpleXMLElement($xml);

        return new MapGbx(
            uid:         (string) ($doc->ident['uid']        ?? ''),
            name:        (string) ($doc->ident['name']       ?? ''),
            author:      (string) ($doc->ident['author']     ?? ''),
            environment: (string) ($doc->desc['envir']       ?? ''),
            mood:        trim((string) ($doc->desc['mood']   ?? '')),
            type:        (string) ($doc->desc['type']        ?? ''),
            bronzeTime:  (int)    ($doc->times['bronze']     ?? 0),
            silverTime:  (int)    ($doc->times['silver']     ?? 0),
            goldTime:    (int)    ($doc->times['gold']       ?? 0),
            authorTime:  (int)    ($doc->times['authortime'] ?? 0),
            price:       (int)    ($doc->desc['price']       ?? 0),
            nblaps:      (int)    ($doc->desc['nblaps']      ?? 0),
        );
    }
}
