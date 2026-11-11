<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\RobloxUtilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DataStoreController extends Controller
{
    const MAX_VALUE = 260000;
    const MAX_NAME = 50;
    const MAX_KEYS = 100;
    const MAX_PAGE = 100;
    const MAX_ENTRIES_PER_PLACE = 200000;

    public function get(Request $request)
    {
        $placeId = $this->authorize($request);
        $type = $this->type($request);
        $wanted = [];
        $fields = self::fields($request->getContent());

        for ($i = 0; $i < self::MAX_KEYS; $i++) {
            if (!isset($fields["qkeys[$i].key"])) {
                break;
            }
            $wanted[] = [
                self::name($fields["qkeys[$i].scope"] ?? 'global'),
                self::name($fields["qkeys[$i].key"]),
                self::name($fields["qkeys[$i].target"] ?? ''),
            ];
        }

        $data = [];
        foreach ($wanted as [$scope, $store, $target]) {
            $row = self::query($placeId, $type, $scope, $store, $target)->first();
            if ($row) {
                $data[] = ['Key' => $store, 'Scope' => $scope, 'Target' => $target, 'Value' => $row->value];
            }
        }

        return response()->json(['data' => $data]);
    }

    public function set(Request $request)
    {
        $placeId = $this->authorize($request);
        $type = $this->type($request);
        [$scope, $store, $target] = self::address($request);
        $fields = self::fields($request->getContent());
        $value = $fields['value'] ?? null;

        if ($value === null || strlen($value) > self::MAX_VALUE) {
            return self::fail('Value is too large');
        }

        $intValue = null;
        if ($type === 'sorted') {
            if (!preg_match('/^-?\d{1,18}$/', trim($value))) {
                return self::fail('Sorted values must be integers');
            }
            $intValue = (int) trim($value);
        }

        return DB::connection('finobe')->transaction(function () use ($placeId, $type, $scope, $store, $target, $value, $intValue, $fields) {
            $row = self::query($placeId, $type, $scope, $store, $target)->lockForUpdate()->first();

            if (array_key_exists('expectedValue', $fields) || request()->query('expectedValueLength') !== null) {
                $expected = $fields['expectedValue'] ?? '';
                $current = $row ? $row->value : '';
                if ($current !== $expected) {
                    return response()->json(['data' => $row ? $row->value : null]);
                }
            }

            if (!$row && self::full($placeId)) {
                return self::fail('This place has too many data store keys');
            }

            self::write($row, $placeId, $type, $scope, $store, $target, $value, $intValue);

            return response()->json(['data' => $value]);
        });
    }

    public function increment(Request $request)
    {
        $placeId = $this->authorize($request);
        $type = $this->type($request);
        [$scope, $store, $target] = self::address($request);
        $delta = (int) $request->query('value', 0);

        return DB::connection('finobe')->transaction(function () use ($placeId, $type, $scope, $store, $target, $delta) {
            $row = self::query($placeId, $type, $scope, $store, $target)->lockForUpdate()->first();
            $current = 0;
            if ($row) {
                $decoded = json_decode($row->value, true);
                if (!is_int($decoded) && !(is_float($decoded) && floor($decoded) == $decoded)) {
                    return self::fail('Cannot increment a value that is not a number');
                }
                $current = (int) $decoded;
            } elseif (self::full($placeId)) {
                return self::fail('This place has too many data store keys');
            }

            $next = $current + $delta;
            self::write($row, $placeId, $type, $scope, $store, $target, (string) $next, $type === 'sorted' ? $next : null);

            return response()->json(['data' => $next]);
        });
    }

    public function sorted(Request $request)
    {
        $placeId = $this->authorize($request);
        $scope = self::name($request->query('scope', 'global'));
        $store = self::name($request->query('key', ''));
        $pageSize = max(1, min(self::MAX_PAGE, (int) $request->query('pageSize', 50)));
        $ascending = strtolower((string) $request->query('ascending', 'false')) === 'true';
        $offset = max(0, (int) $request->query('exclusiveStartKey', 0));

        $query = DB::connection('finobe')->table('datastore_entries')
            ->where('place_id', $placeId)
            ->where('type', 'sorted')
            ->where('scope', $scope)
            ->where('store', $store);

        if ($request->query('inclusiveMinValue') !== null) {
            $query->where('int_value', '>=', (int) $request->query('inclusiveMinValue'));
        }
        if ($request->query('inclusiveMaxValue') !== null) {
            $query->where('int_value', '<=', (int) $request->query('inclusiveMaxValue'));
        }

        $rows = $query->orderBy('int_value', $ascending ? 'asc' : 'desc')
            ->orderBy('target')
            ->offset($offset)
            ->limit($pageSize + 1)
            ->get(['target', 'int_value']);

        $entries = $rows->take($pageSize)->map(fn ($row) => ['Target' => $row->target, 'Value' => (int) $row->int_value])->values();

        return response()->json(['data' => [
            'Entries' => $entries,
            'ExclusiveStartKey' => $rows->count() > $pageSize ? (string) ($offset + $pageSize) : null,
        ]]);
    }

    private function authorize(Request $request): int
    {
        $placeId = (int) $request->query('placeId', 0);
        $jobId = (string) $request->header('Roblox-Game-Id', '');

        if ($placeId <= 0 || !preg_match('/^[0-9a-fA-F-]{36}$/', $jobId) || !RobloxUtilities::IsTrustedServerRequest()) {
            abort(response()->json(['error' => 'Forbidden'], 403));
        }

        $running = DB::connection('finobe')->table('servers')->where('jobId', $jobId)->value('placeid');
        $closed = Cache::get('datastore-closed-job:' . $jobId);

        if ((int) ($running ?? $closed) !== $placeId) {
            abort(response()->json(['error' => 'Forbidden'], 403));
        }

        return $placeId;
    }

    private function type(Request $request): string
    {
        return $request->query('type') === 'sorted' ? 'sorted' : 'standard';
    }

    private static function address(Request $request): array
    {
        return [
            self::name($request->query('scope', 'global')),
            self::name($request->query('key', '')),
            self::name($request->query('target', '')),
        ];
    }

    private static function name($value): string
    {
        $value = (string) $value;
        if (strlen($value) > self::MAX_NAME) {
            abort(self::fail('Key name is too long'));
        }

        return $value;
    }

    private static function fields(string $body): array
    {
        $fields = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[urldecode($name)] = urldecode($value);
        }

        return $fields;
    }

    private static function query(int $placeId, string $type, string $scope, string $store, string $target)
    {
        return DB::connection('finobe')->table('datastore_entries')
            ->where('place_id', $placeId)
            ->where('type', $type)
            ->where('scope', $scope)
            ->where('store', $store)
            ->where('target', $target);
    }

    private static function full(int $placeId): bool
    {
        return DB::connection('finobe')->table('datastore_entries')->where('place_id', $placeId)->count() >= self::MAX_ENTRIES_PER_PLACE;
    }

    private static function write($row, int $placeId, string $type, string $scope, string $store, string $target, string $value, ?int $intValue): void
    {
        $now = now();
        if ($row) {
            DB::connection('finobe')->table('datastore_entries')->where('id', $row->id)
                ->update(['value' => $value, 'int_value' => $intValue, 'updated_at' => $now]);
            return;
        }

        DB::connection('finobe')->table('datastore_entries')->insert([
            'place_id' => $placeId,
            'type' => $type,
            'scope' => $scope,
            'store' => $store,
            'target' => $target,
            'value' => $value,
            'int_value' => $intValue,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function fail(string $message)
    {
        return response()->json(['error' => $message], 400);
    }
}
