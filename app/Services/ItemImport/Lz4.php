<?php

namespace App\Services\ItemImport;

class Lz4
{
    public static function decompressBlock(string $in, int $size): string
    {
        $out = '';
        $i = 0;
        $n = strlen($in);

        while ($i < $n) {
            $token = ord($in[$i++]);
            $lit = $token >> 4;
            if ($lit === 15) {
                do {
                    $c = ord($in[$i++]);
                    $lit += $c;
                } while ($c === 255);
            }
            $out .= substr($in, $i, $lit);
            $i += $lit;
            if ($i >= $n) {
                break;
            }

            $offset = ord($in[$i]) | (ord($in[$i + 1]) << 8);
            $i += 2;
            $len = $token & 15;
            if ($len === 15) {
                do {
                    $c = ord($in[$i++]);
                    $len += $c;
                } while ($c === 255);
            }
            $len += 4;
            if ($offset === 0 || $offset > strlen($out)) {
                throw new ImportException('The model file is damaged (bad compressed data).');
            }
            $start = strlen($out) - $offset;
            if ($offset >= $len) {
                $out .= substr($out, $start, $len);
            } else {
                for ($k = 0; $k < $len; $k++) {
                    $out .= $out[$start + $k];
                }
            }
        }

        if (strlen($out) !== $size) {
            throw new ImportException('The model file is damaged (size mismatch).');
        }
        return $out;
    }
}
