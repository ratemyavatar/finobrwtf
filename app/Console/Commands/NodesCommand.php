<?php

namespace App\Console\Commands;

use App\Models\GameNode;
use Illuminate\Console\Command;

class NodesCommand extends Command
{
    protected $signature = 'finobe:nodes
        {action=list : list|add|enable|disable|remove}
        {--id= : node id (enable/disable/remove)}
        {--name= : node name (add)}
        {--client=any : 2012, 2016 or any (add)}
        {--public-ip= : address players connect to (add)}
        {--control-url= : base URL the web server calls (add)}
        {--port-min= : first UDP port (add)}
        {--port-max= : last UDP port (add)}
        {--weight=1 : selection weight (add)}';

    protected $description = 'Manage the game-server node pool';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->list(),
            'add' => $this->add(),
            'enable', 'disable' => $this->toggle(),
            'remove' => $this->remove(),
            default => $this->abort('Unknown action (list|add|enable|disable|remove)'),
        };
    }

    private function list(): int
    {
        $nodes = GameNode::orderBy('id')->get();
        if ($nodes->isEmpty()) {
            $this->warn('No nodes configured. The site falls back to FINOBE_GAMESERVER_HOST / FINOBE_HOST2012_URL.');
            return self::SUCCESS;
        }
        $this->table(
            ['id', 'name', 'version', 'public_ip', 'control_url', 'ports', 'enabled', 'load'],
            $nodes->map(fn ($n) => [
                $n->id, $n->name, $n->version, $n->public_ip, $n->control_url,
                "{$n->port_min}-{$n->port_max}", $n->enabled ? 'yes' : 'no',
                \App\Services\GameNodes::load($n),
            ])->all()
        );
        return self::SUCCESS;
    }

    private function add(): int
    {
        foreach (['name', 'public-ip', 'control-url', 'port-min', 'port-max'] as $opt) {
            if (!$this->option($opt)) {
                return $this->abort("--{$opt} is required");
            }
        }
        $node = GameNode::create([
            'name' => $this->option('name'),
            'version' => $this->option('client') ?: 'any',
            'public_ip' => $this->option('public-ip'),
            'control_url' => $this->option('control-url'),
            'port_min' => (int) $this->option('port-min'),
            'port_max' => (int) $this->option('port-max'),
            'enabled' => true,
            'weight' => (int) $this->option('weight') ?: 1,
        ]);
        $this->info("Added node {$node->id} ({$node->name}).");
        return self::SUCCESS;
    }

    private function toggle(): int
    {
        $node = GameNode::find((int) $this->option('id'));
        if (!$node) {
            return $this->abort('No such node id');
        }
        $node->enabled = $this->argument('action') === 'enable';
        $node->save();
        $this->info("Node {$node->id} " . ($node->enabled ? 'enabled' : 'disabled') . '.');
        return self::SUCCESS;
    }

    private function remove(): int
    {
        $node = GameNode::find((int) $this->option('id'));
        if (!$node) {
            return $this->abort('No such node id');
        }
        $node->delete();
        $this->info("Removed node {$node->id}.");
        return self::SUCCESS;
    }

    private function abort(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}