<?php
namespace Funseco\Tmx;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Chat\Facades\Chat;

/** Clicks in the /xlist window: the same commands a player would type (their guards apply). */
class XlistAction
{
    public function handle(Player $player, string $action): void
    {
        if (!preg_match('/^tmx_xlist_(\d+)_(id|add|adm|auth)$/', $action, $m)) {
            return;
        }
        $state = state()->get(self::key($player->login));
        $row = $state['rows'][(int) $m[1] - 1] ?? null;
        if (!$row) {
            return;
        }
        $section = $state['section'];

        match ($m[2]) {
            'id' => Chat::run($player, 'tmxinfo', [(string) $row['id'], $section]),
            'add' => Chat::run($player, 'add', [(string) $row['id'], $section]),
            'adm' => Chat::run($player, 'admin add', [(string) $row['id'], strtolower($section)]),
            'auth' => Chat::run($player, 'xlist', ['auth:'.str_replace(' ', '%20', $row['author']), $section]),
        };
    }

    public static function key(string $login): string
    {
        return 'tmx:xlist:'.$login;
    }
}
