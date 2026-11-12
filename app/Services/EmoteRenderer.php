<?php

namespace App\Services;

use App\Http\Controllers\RobloxArbiterUtilities;
use App\Http\Controllers\RobloxUtilities;
use App\Models\User;

class EmoteRenderer
{
    const OWNER_ID = 1;
    const SIZE = 420;
    const CHUNK = 6;

    const SCRIPT = '
        local characterUrl, url, size, frames, first, last = ...

        settings()["Task Scheduler"].ThreadPoolConfig = Enum.ThreadPoolConfig.PerCore4;
        game:GetService("ContentProvider"):SetThreadPool(16)
        game:GetService("ScriptContext").ScriptsDisabled = true
        pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)

        local player = game:GetService("Players"):CreateLocalPlayer(0)
        player.CharacterAppearance = characterUrl
        player:LoadCharacter(false)
        local character = player.Character
        local torso = character.Torso
        local root = character:FindFirstChild("HumanoidRootPart")

        local list = {}
        for frame in string.gmatch(frames, "[^#]+") do list[#list + 1] = frame end

        local function apply(frame)
            for name, numbers in string.gmatch(frame, "([^|;]+)|([^;]+)") do
                local v = {}
                for number in string.gmatch(numbers, "%S+") do v[#v + 1] = tonumber(number) or 0 end
                local joint = torso:FindFirstChild(name) or (root and root:FindFirstChild(name))
                if joint and #v == 24 then
                    joint.C0 = CFrame.new(v[1], v[2], v[3], v[4], v[5], v[6], v[7], v[8], v[9], v[10], v[11], v[12])
                    joint.C1 = CFrame.new(v[13], v[14], v[15], v[16], v[17], v[18], v[19], v[20], v[21], v[22], v[23], v[24])
                end
            end
        end

        local parts = {}
        local function collect(parent)
            for _, child in pairs(parent:GetChildren()) do
                if child:IsA("BasePart") and child.Name ~= "HumanoidRootPart" then parts[#parts + 1] = child end
                collect(child)
            end
        end
        collect(character)

        local low, high = nil, nil
        for _, frame in pairs(list) do
            apply(frame)
            for _, part in pairs(parts) do
                local half = part.Size / 2
                for _, sx in pairs({ -1, 1 }) do
                    for _, sy in pairs({ -1, 1 }) do
                        for _, sz in pairs({ -1, 1 }) do
                            local point = part.CFrame * Vector3.new(half.X * sx, half.Y * sy, half.Z * sz)
                            if low == nil then
                                low, high = point, point
                            else
                                low = Vector3.new(math.min(low.X, point.X), math.min(low.Y, point.Y), math.min(low.Z, point.Z))
                                high = Vector3.new(math.max(high.X, point.X), math.max(high.Y, point.Y), math.max(high.Z, point.Z))
                            end
                        end
                    end
                end
            end
        end

        if low ~= nil then
            local box = Instance.new("Part")
            box.Name = "EmoteBounds"
            box.Anchored = true
            box.CanCollide = false
            box.Transparency = 1
            pcall(function() box.FormFactor = Enum.FormFactor.Custom end)
            box.Size = high - low
            box.CFrame = CFrame.new((low + high) / 2)
            box.Parent = character
        end

        game:GetService("ThumbnailGenerator").GraphicsMode = 4
        local out = {}
        for i = first, last do
            if list[i] then
                apply(list[i])
                out[#out + 1] = game:GetService("ThumbnailGenerator"):Click("PNG", size, size, true)
            end
        end
        return table.concat(out, ";")
    ';

    public static function allowed(User $user): bool
    {
        return (int) $user->id === self::OWNER_ID;
    }

    public static function emoteFor(User $user): ?array
    {
        if (!self::allowed($user)) {
            return null;
        }
        $avatar = is_array($user->avatar) ? $user->avatar : [];
        $slug = (string) ($avatar[0]['emote'] ?? '');

        return $slug !== '' ? self::dance($slug) : null;
    }

    public static function dances(): array
    {
        $out = [];
        $list = @file(storage_path('rbx/files/dances/list.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($list as $line) {
            [$id, $slug, $title] = array_pad(explode("\t", $line, 3), 3, '');
            if (preg_match('/^[a-z0-9]{1,64}$/', $slug)) {
                $out[$slug] = ['id' => (int) $id, 'slug' => $slug, 'title' => $title];
            }
        }

        return $out;
    }

    public static function dance(string $slug): ?array
    {
        return self::dances()[$slug] ?? null;
    }

    public static function thumbnail(string $slug): ?string
    {
        $file = storage_path("rbx/files/dances/thumbs/{$slug}.png");

        return preg_match('/^[a-z0-9]{1,64}$/', $slug) && is_file($file) ? $file : null;
    }

    public static function render(User $user, string $slug): string
    {
        $file = storage_path("rbx/files/dances/{$slug}.rbxmx.gz");
        if (!preg_match('/^[a-z0-9]{1,64}$/', $slug) || !is_file($file)) {
            throw new \RuntimeException("Unknown emote {$slug}");
        }

        [$frames, $delay] = PoseExtractor::frames((string) gzdecode((string) file_get_contents($file)), 10, 6, 60);
        @set_time_limit(600);
        @ini_set('default_socket_timeout', '300');
        $encoded = implode('#', array_map(fn ($joints) => PoseExtractor::encode($joints), $frames));

        $site = 'https://www.' . config('finobe.domain');
        $arbiter = new RobloxArbiterUtilities(config('finobe.arbiter.host'), (int) config('finobe.arbiter.port'));
        $images = [];
        for ($first = 1; $first <= count($frames); $first += self::CHUNK) {
            $last = min(count($frames), $first + self::CHUNK - 1);
            $chunk = null;
            $error = 'no reply';
            for ($attempt = 0; $attempt < 2 && $chunk === null; $attempt++) {
                $job = $arbiter->ConstructJob(
                    RobloxUtilities::GenerateGUID(),
                    self::SCRIPT,
                    120,
                    0,
                    2,
                    'ScriptExecution',
                    ["{$site}/asset/CharacterFetch.ashx?userId={$user->id}", $site, self::SIZE, $encoded, $first, $last]
                );
                $result = $arbiter->SoapCallService('OpenJobEx', $job);
                if (is_soap_fault($result)) {
                    $error = $result->getMessage();
                    continue;
                }
                $value = $result->LuaValue ?? null;
                $value = is_array($value) ? ($value[0]->value ?? null) : ($value->value ?? null);
                $pngs = [];
                foreach (explode(';', (string) $value) as $part) {
                    $png = base64_decode($part, true);
                    if (!$png || !str_starts_with($png, "\x89PNG")) {
                        $pngs = null;
                        $error = 'rcc sent back no image';
                        break;
                    }
                    $pngs[] = $png;
                }
                $chunk = $pngs;
            }
            if ($chunk === null) {
                throw new \RuntimeException("frames {$first}-{$last}: " . trim($error));
            }
            array_push($images, ...$chunk);
        }

        return self::apng($images, $delay);
    }

    public static function apng(array $images, float $delay): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $header = null;
        $frames = [];
        foreach ($images as $png) {
            $offset = 8;
            $ihdr = null;
            $data = '';
            while ($offset + 8 <= strlen($png)) {
                $length = unpack('N', substr($png, $offset, 4))[1];
                $type = substr($png, $offset + 4, 4);
                $body = substr($png, $offset + 8, $length);
                if ($type === 'IHDR') {
                    $ihdr = $body;
                } elseif ($type === 'IDAT') {
                    $data .= $body;
                }
                $offset += 12 + $length;
            }
            if ($ihdr === null || $data === '') {
                throw new \RuntimeException('Bad frame in emote render');
            }
            if ($header === null) {
                $header = $ihdr;
            } elseif (substr($ihdr, 0, 8) !== substr($header, 0, 8) || substr($ihdr, 8) !== substr($header, 8)) {
                throw new \RuntimeException('Emote frames have different sizes');
            }
            $frames[] = $data;
        }

        [$width, $height] = array_values(unpack('N2', substr($header, 0, 8)));
        $delayNum = max(1, (int) round($delay * 1000));
        $out = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $header) . $chunk('acTL', pack('NN', count($frames), 0));
        $sequence = 0;
        foreach ($frames as $index => $data) {
            $out .= $chunk('fcTL', pack('NNNNNnnCC', $sequence++, $width, $height, 0, 0, $delayNum, 1000, 1, 0));
            $out .= $index === 0 ? $chunk('IDAT', $data) : $chunk('fdAT', pack('N', $sequence++) . $data);
        }

        return $out . $chunk('IEND', '');
    }
}
