<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RobloxUtilities extends Controller
{
    protected $db;

    public function __construct() {
        $this->db = DB::connection('finobe');
    }

    public static function GenerateClientTicket(int $userId, string $username, string $charApp, string $jobId): string
    {
        $currentDate = date("n\/j\/Y\ g\:i\:s\ A");
        $ticket = "{$userId}\n{$jobId}\n{$currentDate}";
        $signedTicket = SecurityNotary::CreateSignature($ticket);

        $ticket2 = "{$userId}\n{$username}\n{$charApp}\n{$jobId}\n{$currentDate}";
        $signedTicket2 = SecurityNotary::CreateSignature($ticket2);

        return "{$currentDate};{$signedTicket2};{$signedTicket}";
    }

    public static function GenerateGUID(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
    }

    public static function IsFinobeCloudAuthorized()
    {
        $requestKey = request()->header('AccessKey') ?? request()->query('accesskey', "");
        $exceptKey = config('finobe.cloud_key');
        return is_string($exceptKey) && $exceptKey !== '' && hash_equals($exceptKey, (string)$requestKey);
    }

    public static function IsTrustedServerRequest(): bool
    {
        if (self::IsFinobeCloudAuthorized()) {
            return true;
        }

        $ip = (string) request()->ip();
        if ($ip === '') {
            return false;
        }

        return in_array($ip, self::ServerAddresses(), true);
    }

    public static function ServerAddresses(): array
    {
        $hosts = array_merge(
            [
                config('finobe.arbiter.host'),
                config('finobe.gameserver.host'),
                config('finobe.clients.2012.gameserver_host'),
                parse_url((string) config('finobe.host2012.url'), PHP_URL_HOST),
            ],
            explode(',', (string) config('finobe.server_ips'))
        );

        $addresses = [];
        foreach ($hosts as $host) {
            $host = trim((string) $host);
            if ($host === '') {
                continue;
            }
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $addresses[] = $host;
                continue;
            }
            $resolved = \Illuminate\Support\Facades\Cache::remember('server_host_' . md5($host), 300, fn () => gethostbynamel($host) ?: []);
            $addresses = array_merge($addresses, $resolved);
        }

        return array_values(array_unique($addresses));
    }

    public static function ConstructJoinScript(object $userInformation, object $placeInformation, object $serverInformation, object $additionalInfo, string $joinKey = '')
    {
        $joinKey = preg_replace('/[^0-9a-f]/', '', $joinKey);
        $keyParam = $joinKey !== '' ? "&jk={$joinKey}" : '';
        $format = \App\Services\ClientVersions::joinFormat((string) $additionalInfo->version);
        switch ($format === 'json' ? '2016' : (string) $additionalInfo->version) {
            case "2016":
                $domain = config('finobe.domain');
                $charApp = "https://www.{$domain}/Asset/CharacterFetch.ashx?userId={$userInformation->id}&placeId={$placeInformation->id}{$keyParam}";
                $created = is_numeric($userInformation->created ?? null) ? (int) $userInformation->created : (int) strtotime((string) ($userInformation->created ?? ""));

                return json_encode([
                    "ClientPort" => 0,
                    "MachineAddress" => $serverInformation->ip,
                    "ServerPort" => (int) $serverInformation->port,
                    "PingUrl" => "",
                    "PingInterval" => 0,
                    "UserName" => $userInformation->username,
                    "SeleniumTestMode" => false,
                    "UserId" => (int) $userInformation->id,
                    "SuperSafeChat" => false,
                    "CharacterAppearance" => $charApp,
                    "ClientTicket" => RobloxUtilities::GenerateClientTicket((int) $userInformation->id, $userInformation->username, $charApp, $serverInformation->jobId),
                    "GameId" => $serverInformation->jobId,
                    "PlaceId" => (int) $placeInformation->id,
                    "MeasurementUrl" => "",
                    "WaitingForCharacterGuid" => RobloxUtilities::GenerateGUID(),
                    "BaseUrl" => "https://www.{$domain}/",
                    "ChatStyle" => "ClassicAndBubble",
                    "VendorId" => "0",
                    "ScreenShotInfo" => "",
                    "VideoInfo" => "",
                    "CreatorId" => (int) $placeInformation->author,
                    "CreatorTypeEnum" => "User",
                    "MembershipType" => \App\Services\PlayerBadges::membershipType((int) $userInformation->id),
                    "AccountAge" => $created > 0 ? (int) floor((time() - $created) / 86400) : 0,
                    "CookieStoreFirstTimePlayKey" => "rbx_evt_ftp",
                    "CookieStoreFiveMinutePlayKey" => "rbx_evt_fmp",
                    "CookieStoreEnabled" => true,
                    "IsRobloxPlace" => (int) $placeInformation->author === 1,
                    "GenerateTeleportJoin" => false,
                    "IsUnknownOrUnder13" => false,
                    "SessionId" => RobloxUtilities::GenerateGUID() . "|{$serverInformation->jobId}|0|127.0.0.1|8|" . date('dmy') . "|0|null|null",
                    "DataCenterId" => 0,
                    "UniverseId" => (int) $placeInformation->id,
                    "BrowserTrackerId" => 0,
                    "UsePortraitMode" => false,
                    "FollowUserId" => 0,
                    "characterAppearanceId" => (int) $userInformation->id
                ], JSON_UNESCAPED_SLASHES);
            case "2012":

                $joinscript = file_get_contents(storage_path("rbx/files/scripts/join2012.lua"));

                if (!file_exists(storage_path("rbx/files/scripts/join2012.lua"))) {
                    return "I'm so tired bruhh";
                }

                $joinscript = str_replace("{placeId}", (string) (int) $placeInformation->id, $joinscript);
                $joinscript = str_replace("{jobId}", preg_replace('/[^A-Za-z0-9-]/', '', (string) $serverInformation->jobId), $joinscript);

                $serverAddress = 'localhost';
                $joinscript = str_replace("{serverip}", $serverAddress, $joinscript);
                $joinscript = str_replace("{creatorId}", (string) (int) $placeInformation->author, $joinscript);
                $joinscript = str_replace("{chatstyle}", "ClassicAndBubble", $joinscript);
                $joinscript = str_replace("{serverport}", (string) (int) $serverInformation->port, $joinscript);
                $joinscript = str_replace("{userid}", (string) (int) $userInformation->id, $joinscript);
                $joinscript = str_replace("{username}", preg_replace('/[^A-Za-z0-9_ .-]/', '', (string) $userInformation->username), $joinscript);

                $charAppUrl = "http://assetgame." . config('finobe.domain') . "/Asset/CharacterFetch.ashx?userId={$userInformation->id}&placeId={$placeInformation->id}{$keyParam}";
                $joinscript = str_replace("{charapp}", $charAppUrl, $joinscript);
                return $joinscript;
            default:
                return "Not implemented yet!";
        }
    }
}
