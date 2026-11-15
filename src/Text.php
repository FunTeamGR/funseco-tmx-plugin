<?php
namespace Funseco\Tmx;

use Funseco\Core\Models\Player;

final class Text
{
    public static function get(string $key, string|int ...$values): string
    {
        $text = config("tmx.messages.$key", '');
        $text = format_colors(str_replace('{br}', "\n", $text));   // colour tokens first: values must not expand {#..}
        $map = [];
        foreach ($values as $i => $value) {
            $map['{'.($i + 1).'}'] = (string) $value;
        }

        return strtr($text, $map);
    }

    public static function tell(Player $player, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $player->login);
    }

    public static function broadcast(string $message): void
    {
        \Server::query()->ChatSendServerMessage($message);
    }

    public static function stripColors(?string $text): string
    {
        $text = str_replace('$$', "\0", (string) $text);
        $text = preg_replace('/\$(?:[0-9a-f][^$]{0,2}|[lh]\[[^\]]*\]|[^$]?)/i', '', $text);

        return str_replace("\0", '$', $text);
    }

    /** 23770 → "0:23.77" (minutes without leading zero). */
    public static function time(int $ms): string
    {
        return sprintf('%d:%02d.%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000), intdiv($ms % 1000, 10));
    }
}
