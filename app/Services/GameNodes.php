<?php

namespace App\Services;

use App\Models\GameNode;
use Illuminate\Support\Facades\DB;

class GameNodes
{
    public static function forVersion(string $version)
    {
        return GameNode::query()
            ->where('enabled', true)
            ->whereIn('version', [$version, 'any'])
            ->orderBy('id')
            ->get();
    }

    public static function pick(string $version): ?GameNode
    {
        $best = null;
        $bestLoad = null;

        foreach (self::forVersion($version) as $node) {
            if (self::freePort($node) === null) {
                continue;
            }

            $load = self::load($node);
            if ($best === null || $load < $bestLoad) {
                $best = $node;
                $bestLoad = $load;
            }
        }

        return $best;
    }

    public static function load(GameNode $node): int
    {
        return DB::connection('finobe')->table('servers')
            ->where('node_id', $node->id)
            ->whereIn('status', ['1', '2'])
            ->count();
    }

    public static function freePort(GameNode $node): ?int
    {
        $min = (int) $node->port_min;
        $max = (int) $node->port_max;

        if ($max < $min) {
            return null;
        }

        $used = DB::connection('finobe')->table('servers')->pluck('port')->map(fn ($p) => (int) $p)->all();
        $free = array_values(array_diff(range($min, $max), $used));

        return empty($free) ? null : $free[random_int(0, count($free) - 1)];
    }

    public static function forServer(object $server): ?GameNode
    {
        $id = (int) ($server->node_id ?? 0);

        return $id > 0 ? GameNode::find($id) : null;
    }
}
