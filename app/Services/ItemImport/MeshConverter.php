<?php

namespace App\Services\ItemImport;

class MeshConverter
{
    public static function toLegacy(string $mesh): string
    {
        if (!preg_match('/^version (\d)\.(\d\d)\r?\n/', $mesh, $m)) {
            throw new ImportException("A mesh in the model isn't a Roblox mesh.");
        }
        $major = (int) $m[1];
        $o = strlen($m[0]);

        if ($major === 2) {
            return self::narrowV2($mesh, $o);
        }
        if ($major < 2) {
            return $mesh;
        }

        [$verts, $faces] = match (true) {
            $major === 3 => self::readV3($mesh, $o),
            $major === 4 || $major === 5 => self::readV4($mesh, $o, $major),
            $major === 6 && !self::isCompressed($mesh, $o) => self::readChunked($mesh, $o),
            $major === 6 || $major === 7 => [null, null],
            default => throw new ImportException("A mesh in the model uses mesh version {$m[1]}.{$m[2]}, which can't be converted for the 2016 client."),
        };
        if ($verts === null) {
            return self::decodeDraco($mesh);
        }

        return self::writeV2($verts, $faces);
    }

    private static function readV3(string $d, int $o): array
    {
        $h = unpack('vheader/Cvert/Cface/vlodSize/vlods/VnumVerts/VnumFaces', $d, $o);
        $o += $h['header'];
        $verts = self::verts($d, $o, $h['numVerts'], $h['vert']);
        $o += $h['numVerts'] * $h['vert'];
        $faces = substr($d, $o, $h['numFaces'] * 12);
        $o += $h['numFaces'] * $h['face'];
        $lods = $h['lods'] ? array_values(unpack('V' . $h['lods'], $d, $o)) : [];

        return [$verts, self::lod0($faces, $lods)];
    }

    private static function readV4(string $d, int $o, int $major): array
    {
        $h = unpack('vheader/vlodType/VnumVerts/VnumFaces/vlods/vbones/VboneNames/vsubsets/ChqLods/Cpad', $d, $o);
        $o += $h['header'];
        $verts = substr($d, $o, $h['numVerts'] * 40);
        $o += $h['numVerts'] * 40;
        if ($h['bones'] > 0) {
            $o += $h['numVerts'] * 8;
        }
        $faces = substr($d, $o, $h['numFaces'] * 12);
        $o += $h['numFaces'] * 12;
        $lods = $h['lods'] ? array_values(unpack('V' . $h['lods'], $d, $o)) : [];

        return [$verts, self::lod0($faces, $lods)];
    }

    private static function readChunked(string $d, int $o): array
    {
        $verts = null;
        $faces = null;
        $lods = [];
        while ($o + 16 <= strlen($d)) {
            $type = rtrim(substr($d, $o, 8), "\0");
            [, $version, $size] = unpack('V2', $d, $o + 8);
            $body = substr($d, $o + 16, $size);
            $o += 16 + $size;

            if ($type === 'COREMESH') {
                $n = unpack('V', $body)[1];
                $verts = substr($body, 4, $n * 40);
                $nf = unpack('V', $body, 4 + $n * 40)[1];
                $faces = substr($body, 8 + $n * 40, $nf * 12);
            } elseif ($type === 'LODS' && $version === 1) {
                $count = unpack('V', $body, 3)[1];
                $lods = array_values(unpack('V' . $count, $body, 7));
            }
        }
        if ($verts === null) {
            throw new ImportException("A mesh in the model has no geometry the 2016 client can use.");
        }
        return [$verts, self::lod0($faces, $lods)];
    }

    private static function isCompressed(string $d, int $o): bool
    {
        while ($o + 16 <= strlen($d)) {
            [, $version, $size] = unpack('V2', $d, $o + 8);
            if (rtrim(substr($d, $o, 8), "\0") === 'COREMESH') {
                return $version !== 1;
            }
            $o += 16 + $size;
        }
        return false;
    }

    private static function decodeDraco(string $mesh): string
    {
        $script = base_path('tools/mesh/v7_to_v2.py');
        $python = (string) config('finobe.python');
        $proc = @proc_open([$python, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new ImportException('A mesh in the model is a compressed (7.00) mesh, and python3 isn\'t available to convert it.');
        }
        fwrite($pipes[0], $mesh);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code === 3) {
            throw new ImportException('A mesh in the model is a compressed (7.00) mesh. To convert those, run "pip install DracoPy" on the server.');
        }
        if ($code !== 0 || !str_starts_with((string) $out, 'version 2.00')) {
            report(new \RuntimeException("v7_to_v2.py failed ({$code}): {$err}"));
            throw new ImportException("A compressed mesh in the model couldn't be converted.");
        }
        return $out;
    }

    public static function center(string $mesh): ?array
    {
        if (!str_starts_with($mesh, 'version 2.00')) {
            return null;
        }
        $o = strpos($mesh, "\n") + 1;
        $h = unpack('vheader/Cvert/Cface/Vn/Vnf', $mesh, $o);
        $o += $h['header'];
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        for ($i = 0; $i < $h['n']; $i++) {
            $p = array_values(unpack('g3', $mesh, $o + $i * $h['vert']));
            for ($k = 0; $k < 3; $k++) {
                $min[$k] = min($min[$k], $p[$k]);
                $max[$k] = max($max[$k], $p[$k]);
            }
        }
        return $h['n'] ? [($min[0] + $max[0]) / 2, ($min[1] + $max[1]) / 2, ($min[2] + $max[2]) / 2] : null;
    }

    private static function lod0(string $faces, array $lods): string
    {
        if (count($lods) >= 2 && $lods[1] > $lods[0]) {
            return substr($faces, $lods[0] * 12, ($lods[1] - $lods[0]) * 12);
        }
        return $faces;
    }

    private static function verts(string $d, int $o, int $n, int $size): string
    {
        if ($size === 40) {
            return substr($d, $o, $n * 40);
        }
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= str_pad(substr($d, $o + $i * $size, min($size, 32)), 36, "\0") . "\xFF\xFF\xFF\xFF";
        }
        return $out;
    }

    private static function writeV2(string $verts, string $faces): string
    {
        $n = intdiv(strlen($verts), 40);
        $nf = intdiv(strlen($faces), 12);
        $out = "version 2.00\n" . pack('vCCVV', 12, 36, 12, $n, $nf);
        for ($i = 0; $i < $n; $i++) {
            $out .= substr($verts, $i * 40, 32) . "\0\0\0\0";
        }
        return $out . $faces;
    }

    private static function narrowV2(string $mesh, int $o): string
    {
        $h = unpack('vheader/Cvert/Cface/Vn/Vnf', $mesh, $o);
        if ($h['vert'] <= 36 || $h['face'] !== 12) {
            return $mesh;
        }
        $b = $o + $h['header'];
        if (strlen($mesh) < $b + $h['n'] * $h['vert'] + $h['nf'] * 12) {
            throw new ImportException("A mesh in the model is cut short.");
        }
        $out = substr($mesh, 0, $o) . pack('vCCVV', 12, 36, 12, $h['n'], $h['nf']);
        for ($i = 0; $i < $h['n']; $i++) {
            $out .= substr($mesh, $b + $i * $h['vert'], 36);
        }
        return $out . substr($mesh, $b + $h['n'] * $h['vert'], $h['nf'] * 12);
    }
}
