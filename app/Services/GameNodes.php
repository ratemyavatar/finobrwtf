<?php

namespace App\Services;

use App\Models\GameNode;
use Illuminate\Support\Facades\DB;

/*
    GameNodes
     - picks which machine a new game server runs on and which UDP port it gets
     - a node's port range must not overlap another node's that shares the same public_ip (players
       reach both through the same address, so the port has to be unique across them)
*/
class GameNodes
{
    /** enabled nodes that can host this client version, oldest first */
    public static function forVersion(string $version)
    {
        return GameNode::query()
            ->where('enabled', true)
            ->whereIn('version', [$version, 'any'])
            ->orderBy('id')
            ->get();
    }

    /** least-loaded node for this version that still has a free port, or null when there is none */
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

    /** how many servers are starting or running on this node */
    public static function load(GameNode $node): int
    {
        return DB::connection('finobe')->table('servers')
            ->where('node_id', $node->id)
            ->whereIn('status', ['1', '2'])
            ->count();
    }

    /** a free port in the node's range (never one any server already holds), or null when the node is full */
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

    /** the node a running server belongs to (from its servers row), or null */
    public static function forServer(object $server): ?GameNode
    {
        $id = (int) ($server->node_id ?? 0);

        return $id > 0 ? GameNode::find($id) : null;
    }
}
