<?php

namespace App\Console\Commands;

use App\Http\Controllers\Asset as AssetHelper;
use App\Services\ItemImport\ItemImporter;
use Illuminate\Console\Command;

class MakeToyBox extends Command
{
    protected $signature = 'finobe:make-toybox
        {--author=1 : user id that makes and owns the package}
        {--price=0 : price in dius}
        {--onsale : put the package on sale}';

    protected $description = 'Make the Finobe toy box package';

    public function handle(): int
    {
        $dir = storage_path('rbx/files/toybox');
        $author = (int) $this->option('author');
        $domain = config('finobe.domain');

        $template = file_get_contents("{$dir}/toybox.rbxmx");
        foreach (['BANNER' => 'banner.png', 'CARD' => 'card.png', 'LABEL' => 'label.png'] as $key => $file) {
            $id = AssetHelper::createAsset("Finobe Toy Box Texture", 1, $author, file_get_contents("{$dir}/{$file}"), '', 'n', [], true);
            $template = str_replace('{' . $key . '}', "http://www.{$domain}/asset/?id={$id}", $template);
        }

        $hatId = ItemImporter::import($template, 'Finobe Toy Box', $author, 'A Finobe toy box.', 0, false, ItemImporter::HAT);
        $packageId = AssetHelper::createPackage('Finobe Toy Box', [$hatId], $author, 'Become a Finobe toy.', (int) $this->option('price'), (bool) $this->option('onsale'));

        $this->info("made the toy box hat {$hatId} and package {$packageId}");

        return self::SUCCESS;
    }
}
