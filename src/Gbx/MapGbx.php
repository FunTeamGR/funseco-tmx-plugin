<?php
namespace Funseco\Tmx\Gbx;

class MapGbx
{
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly string $author,
        public readonly string $environment,
        public readonly string $mood,
        public readonly string $type,
        public readonly int    $bronzeTime,
        public readonly int    $silverTime,
        public readonly int    $goldTime,
        public readonly int    $authorTime,
        public readonly int    $price,
        public readonly int    $nblaps,
    ) {}

    public function safeFilename(): string
    {
        // Strip TM color codes ($xxx or $xx) and sanitize for filesystem
        $name = preg_replace('/\$(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{2}|[iIoObBuUsSwWnNzZgGlLtT<>]|\$)/', '', $this->name);
        $name = trim(preg_replace('/[^a-zA-Z0-9_\-. ]/', '_', $name));

        return $name ?: "map_{$this->uid}";
    }
}
