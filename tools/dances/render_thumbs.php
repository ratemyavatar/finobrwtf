<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\RobloxArbiterUtilities;
use App\Http\Controllers\RobloxUtilities;
use App\Services\PoseExtractor;

$script = <<<'LUA'
local url, joints = ...
pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
game:GetService("ScriptContext").ScriptsDisabled = true
local player = game:GetService("Players"):CreateLocalPlayer(0)
player:LoadCharacter(false)
local character = player.Character
for _, child in pairs(character:GetChildren()) do
	if child:IsA("Accoutrement") or child:IsA("Hat") or child:IsA("Clothing") or child:IsA("ShirtGraphic") or child:IsA("BodyColors") or child:IsA("CharacterMesh") or child:IsA("Tool") then
		child:Destroy()
	end
end
for _, part in pairs(character:GetChildren()) do
	if part:IsA("BasePart") then
		part.BrickColor = BrickColor.new("Institutional white")
		for _, decal in pairs(part:GetChildren()) do
			if decal:IsA("Decal") then decal:Destroy() end
		end
	end
end
local torso = character.Torso
local root = character:FindFirstChild("HumanoidRootPart")
for name, numbers in string.gmatch(joints, "([^|;]+)|([^;]+)") do
	local v = {}
	for number in string.gmatch(numbers, "%S+") do v[#v + 1] = tonumber(number) or 0 end
	local joint = torso:FindFirstChild(name) or (root and root:FindFirstChild(name))
	if joint and #v == 24 then
		joint.C0 = CFrame.new(v[1], v[2], v[3], v[4], v[5], v[6], v[7], v[8], v[9], v[10], v[11], v[12])
		joint.C1 = CFrame.new(v[13], v[14], v[15], v[16], v[17], v[18], v[19], v[20], v[21], v[22], v[23], v[24])
	end
end
game:GetService("ThumbnailGenerator").GraphicsMode = 4
return game:GetService("ThumbnailGenerator"):Click("PNG", 200, 200, true)
LUA;

$folder = $argv[1] ?? storage_path('rbx/files/dances');
$only = $argv[2] ?? '';
@mkdir("{$folder}/thumbs", 0755, true);
$site = 'https://www.' . config('finobe.domain');
$arbiter = new RobloxArbiterUtilities(config('finobe.arbiter.host'), (int) config('finobe.arbiter.port'));

foreach (file("{$folder}/list.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$id, $slug] = explode("\t", $line);
    if ($only !== '' && $only !== $slug) {
        continue;
    }
    [$joints] = PoseExtractor::fromFile(gzdecode(file_get_contents("{$folder}/{$slug}.rbxmx.gz")), '', 0);
    $job = $arbiter->ConstructJob(RobloxUtilities::GenerateGUID(), $script, 60, 0, 1, 'ScriptExecution', [$site, PoseExtractor::encode($joints)]);
    $png = base64_decode((string) $arbiter->OpenJobEx($job), true);
    if (!$png || !str_starts_with($png, "\x89PNG")) {
        echo "{$slug} failed\n";
        continue;
    }
    file_put_contents("{$folder}/thumbs/{$slug}.png", $png);
    echo "{$slug} ok\n";
}
