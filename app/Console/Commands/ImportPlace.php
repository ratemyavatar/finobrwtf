<?php

namespace App\Console\Commands;

use App\Http\Controllers\Asset as AssetHelper;
use App\Models\Asset;
use App\Models\User;
use App\Services\PlaceThumbnail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportPlace extends Command
{
    protected $signature = 'finobe:import-place
        {file : the place file (.rbxl, .rbxlx, or either gzipped)}
        {--image=* : an image to upload, as name=path. {image:name} in the place becomes its rbxassetid}
        {--place= : id of a place to replace the file of, instead of making a new place}
        {--title= : title for a new place}
        {--description= : description for a new place}
        {--author=1 : user id that owns the new place and the images}
        {--client=2016 : client version for a new place}
        {--maxplayers=15 : max players for a new place}
        {--no-thumbnail : do not render the place thumbnail}
        {--dry-run : check everything and print what would happen, without changing anything}';

    protected $description = 'Upload a place and its images, making a new game or updating one';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $contents = $this->readPlace((string) $this->argument('file'));
        if ($contents === null) {
            return self::FAILURE;
        }

        $author = (int) $this->option('author');
        if (!User::find($author)) {
            $this->error("there is no user {$author}");
            return self::FAILURE;
        }

        $place = null;
        if ($this->option('place') !== null) {
            $place = Asset::where('id', (int) $this->option('place'))->where('asset_type', 9)->first();
            if (!$place) {
                $this->error('there is no place ' . $this->option('place'));
                return self::FAILURE;
            }
        } elseif (!$this->option('title')) {
            $this->error('give --title for a new place, or --place to update one');
            return self::FAILURE;
        }

        $images = $this->readImages();
        if ($images === null) {
            return self::FAILURE;
        }

        preg_match_all('/\{image:([A-Za-z0-9_-]+)\}/', $contents, $m);
        $wanted = array_unique($m[1]);
        $missing = array_diff($wanted, array_keys($images));
        if ($missing) {
            $this->error('the place needs these images: ' . implode(', ', $missing));
            return self::FAILURE;
        }
        foreach (array_diff(array_keys($images), $wanted) as $unused) {
            $this->warn("the place does not use the image {$unused}");
        }

        $previous = $place ? ($place->additional['importedImages'] ?? []) : [];
        $imported = $previous;
        foreach ($images as $name => $data) {
            $sha = sha1($data);
            $old = $previous[$name] ?? null;
            if ($old && ($old['sha1'] ?? null) === $sha && Asset::where('id', $old['id'])->exists()) {
                $id = (int) $old['id'];
                $this->line("image {$name}: reusing {$id}");
            } elseif ($dry) {
                $id = 0;
                $this->line("image {$name}: would upload " . strlen($data) . ' bytes');
            } else {
                $title = ($place ? $place->title : $this->option('title')) . ' ' . $name;
                $id = AssetHelper::createAsset($title, 1, $author, $data, '', 'n', []);
                $this->line("image {$name}: uploaded as {$id}");
            }
            $imported[$name] = ['id' => $id, 'sha1' => $sha];
            $contents = str_replace('{image:' . $name . '}', (string) $id, $contents);
        }

        if ($dry) {
            $this->info($place ? "would replace the file of place {$place->id} ({$place->title})" : 'would make a new place called ' . $this->option('title'));
            return self::SUCCESS;
        }

        if ($place) {
            $hash = uniqid();
            File::put(AssetHelper::ensureDirectory(config('finobe.cdn_path') . '/assets') . "/{$hash}", $contents);
            $old = $place->file;
            $additional = $place->additional;
            $additional['importedImages'] = $imported;
            $place->file = $hash;
            $place->additional = $additional;
            $place->save();
            if ($old && $old !== $hash) {
                File::delete(config('finobe.cdn_path') . '/assets/' . basename($old));
            }
            $id = (int) $place->id;
            $this->info("replaced the file of place {$id} ({$place->title})");
            $render = empty($additional['media']['customThumbnail']);
        } else {
            $id = AssetHelper::createAsset(trim((string) $this->option('title')), 9, $author, $contents, trim((string) $this->option('description')), 'n', [
                'visits' => 0,
                'version' => (string) $this->option('client'),
                'maxplayers' => (int) $this->option('maxplayers'),
                'category' => 'original',
                'featured' => false,
                'gears' => ['combat' => true, 'social' => true, 'building' => true, 'musical' => true],
                'uncopylocked' => false,
                'allowplaying' => true,
                'chat_type' => 'classic',
                'media' => ['imageAssetId' => 0],
                'hidden' => false,
                'importedImages' => $imported,
            ]);
            $this->info("made place {$id}");
            $render = true;
        }

        if ($render && !$this->option('no-thumbnail')) {
            try {
                PlaceThumbnail::render($id);
                $this->line('rendered the thumbnail');
            } catch (\Throwable $e) {
                $this->warn('the thumbnail did not render: ' . $e->getMessage());
            }
        }

        $this->info('https://' . config('finobe.domain') . "/place/{$id}");

        return self::SUCCESS;
    }

    private function readPlace(string $path): ?string
    {
        if (!is_file($path)) {
            $this->error("no file at {$path}");
            return null;
        }
        $contents = file_get_contents($path);
        if (str_starts_with($contents, "\x1f\x8b")) {
            $contents = @gzdecode($contents);
            if ($contents === false) {
                $this->error("{$path} is not a working gzip file");
                return null;
            }
        }
        if (!preg_match('/^\s*<roblox[!\s]/', substr($contents, 0, 64))) {
            $this->error("{$path} is not a place file");
            return null;
        }
        if (strlen($contents) > 50 * 1024 * 1024) {
            $this->error('the place is over 50 MB');
            return null;
        }
        return $contents;
    }

    private function readImages(): ?array
    {
        $images = [];
        foreach ((array) $this->option('image') as $pair) {
            if (!preg_match('/^([A-Za-z0-9_-]+)=(.+)$/', $pair, $m)) {
                $this->error("--image wants name=path, got {$pair}");
                return null;
            }
            if (!is_file($m[2])) {
                $this->error("no image at {$m[2]}");
                return null;
            }
            $data = file_get_contents($m[2]);
            if (!str_starts_with($data, "\x89PNG") && !str_starts_with($data, "\xFF\xD8")) {
                $this->error("{$m[2]} is not a png or jpg");
                return null;
            }
            $images[$m[1]] = $data;
        }
        return $images;
    }
}
