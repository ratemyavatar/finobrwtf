<?php

namespace App\Services;

use App\Services\ItemImport\ImportException;
use App\Services\ItemImport\RbxmReader;
use DOMDocument;
use DOMElement;
use DOMXPath;

class PoseExtractor
{
    const R6 = [
        'RootJoint' => ['HumanoidRootPart', 'Torso', [0, 0, 0, -1, 0, 0, 0, 0, 1, 0, 1, 0], [0, 0, 0, -1, 0, 0, 0, 0, 1, 0, 1, 0]],
        'Neck' => ['Torso', 'Head', [0, 1, 0, -1, 0, 0, 0, 0, 1, 0, 1, 0], [0, -0.5, 0, -1, 0, 0, 0, 0, 1, 0, 1, 0]],
        'Right Shoulder' => ['Torso', 'Right Arm', [1, 0.5, 0, 0, 0, 1, 0, 1, 0, -1, 0, 0], [-0.5, 0.5, 0, 0, 0, 1, 0, 1, 0, -1, 0, 0]],
        'Left Shoulder' => ['Torso', 'Left Arm', [-1, 0.5, 0, 0, 0, -1, 0, 1, 0, 1, 0, 0], [0.5, 0.5, 0, 0, 0, -1, 0, 1, 0, 1, 0, 0]],
        'Right Hip' => ['Torso', 'Right Leg', [1, -1, 0, 0, 0, 1, 0, 1, 0, -1, 0, 0], [0.5, 1, 0, 0, 0, 1, 0, 1, 0, -1, 0, 0]],
        'Left Hip' => ['Torso', 'Left Leg', [-1, -1, 0, 0, 0, -1, 0, 1, 0, 1, 0, 0], [-0.5, 1, 0, 0, 0, -1, 0, 1, 0, 1, 0, 0]],
    ];

    const R15 = [
        'RootJoint' => ['HumanoidRootPart', 'UpperTorso'],
        'Neck' => ['UpperTorso', 'Head'],
        'Right Shoulder' => ['UpperTorso', 'RightUpperArm'],
        'Left Shoulder' => ['UpperTorso', 'LeftUpperArm'],
        'Right Hip' => ['UpperTorso', 'RightUpperLeg'],
        'Left Hip' => ['UpperTorso', 'LeftUpperLeg'],
    ];

    const R15_PARENTS = [
        'LowerTorso' => 'HumanoidRootPart', 'UpperTorso' => 'LowerTorso', 'Head' => 'UpperTorso',
        'RightUpperArm' => 'UpperTorso', 'LeftUpperArm' => 'UpperTorso',
        'RightUpperLeg' => 'LowerTorso', 'LeftUpperLeg' => 'LowerTorso',
    ];

    private DOMXPath $xpath;
    private int $keyframe = 0;

    public static function fromFile(string $file, string $animation = '', int $keyframe = 0): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML(RbxmReader::toXml($file), LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new ImportException("That file couldn't be read.");
        }

        $self = new self();
        $self->xpath = new DOMXPath($doc);

        $self->keyframe = $keyframe;

        if ($animation === '' && $keyframe === 0) {
            $joints = $self->fromRig();
            if ($joints !== null && $self->posed($joints)) {
                return [$joints, 'rig'];
            }
        }

        $sequence = $self->sequence($animation);
        if ($sequence !== null) {
            [$joints, $index, $count] = $self->fromSequence($sequence);
            return [$joints, 'animation "' . $self->name($sequence) . '", keyframe ' . $index . ' of ' . $count];
        }

        if ($animation !== '') {
            throw new ImportException("There's no animation called \"{$animation}\" in that file.");
        }
        if (isset($joints)) {
            return [$joints, 'rig'];
        }

        throw new ImportException("That file has no R6 or R15 character or animation in it.");
    }

    public static function encode(array $joints): string
    {
        $out = [];
        foreach ($joints as $name => [$c0, $c1]) {
            $out[] = $name . '|' . implode(' ', array_map(fn ($v) => self::num($v), array_merge($c0, $c1)));
        }

        return implode(';', $out);
    }

    private function fromRig(): ?array
    {
        foreach ($this->xpath->query('//Item[@class="Model" or @class="Folder" or @class="WorldModel"]') as $model) {
            $parts = $this->parts($model);
            if (isset($parts['Torso'], $parts['Head'])) {
                return $this->r6Rig($model, $parts);
            }
            if (isset($parts['UpperTorso'], $parts['Head'])) {
                return $this->r15Rig($parts);
            }
        }

        return null;
    }

    private function r6Rig(DOMElement $model, array $parts): array
    {
        $motors = $this->motors($model);
        $joints = [];
        foreach (self::R6 as $joint => [$part0, $part1, $c0, $c1]) {
            if (isset($motors[$joint]) && $motors[$joint][0] !== null) {
                $c0 = $motors[$joint][0];
            }
            if (isset($parts[$part0], $parts[$part1])) {
                $c1 = self::mul(self::inv($parts[$part1]), self::mul($parts[$part0], $c0));
            }
            $joints[$joint] = [self::clean($c0), self::clean($c1)];
        }

        return $joints;
    }

    private function r15Rig(array $parts): array
    {
        $joints = [];
        foreach (self::R15 as $joint => [$part0, $part1]) {
            $delta = isset($parts[$part0], $parts[$part1])
                ? self::rotation(self::mul(self::inv($parts[$part0]), $parts[$part1]))
                : self::identity();
            $joints[$joint] = $this->applyDelta($joint, $delta);
        }

        return $joints;
    }

    private function fromSequence(DOMElement $sequence): array
    {
        $frames = [];
        foreach ($this->children($sequence, 'Keyframe') as $frame) {
            $frames[] = [(float) $this->prop($frame, 'Time'), $frame];
        }
        if (!$frames) {
            throw new ImportException("That animation has no keyframes.");
        }
        usort($frames, fn ($a, $b) => $a[0] <=> $b[0]);
        $count = count($frames);

        if ($this->keyframe > 0) {
            if ($this->keyframe > $count) {
                throw new ImportException("That animation only has {$count} keyframe(s).");
            }
            return [$this->fromKeyframe($frames[$this->keyframe - 1][1]), $this->keyframe, $count];
        }

        $best = null;
        $bestScore = -1;
        foreach ($frames as $i => [, $frame]) {
            $joints = $this->fromKeyframe($frame);
            $score = $this->score($joints);
            if ($score > $bestScore + 1e-6) {
                $best = [$joints, $i + 1, $count];
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function fromKeyframe(DOMElement $frame): array
    {
        $transforms = [];
        $this->collectPoses($frame, $transforms);

        if (isset($transforms['UpperTorso']) || isset($transforms['LowerTorso']) || isset($transforms['RightUpperArm'])) {
            $get = fn ($n) => $transforms[$n] ?? self::identity();
            $torso = self::rotation(self::mul($get('LowerTorso'), $get('UpperTorso')));
            $upper = self::rotation($get('UpperTorso'));
            $deltas = [
                'RootJoint' => $torso,
                'Neck' => self::rotation($get('Head')),
                'Right Shoulder' => self::rotation($get('RightUpperArm')),
                'Left Shoulder' => self::rotation($get('LeftUpperArm')),
                'Right Hip' => self::mul(self::inv($upper), self::rotation($get('RightUpperLeg'))),
                'Left Hip' => self::mul(self::inv($upper), self::rotation($get('LeftUpperLeg'))),
            ];
            $joints = [];
            foreach ($deltas as $joint => $delta) {
                $joints[$joint] = $this->applyDelta($joint, $delta);
            }

            return $joints;
        }

        $joints = [];
        foreach (self::R6 as $joint => [, $part1, $c0, $c1]) {
            if (isset($transforms[$part1])) {
                $c1 = self::mul($c1, self::inv($transforms[$part1]));
            }
            $joints[$joint] = [self::clean($c0), self::clean($c1)];
        }

        return $joints;
    }

    private function applyDelta(string $joint, array $delta): array
    {
        [, , $c0, $c1] = self::R6[$joint];
        $c0Rotation = self::rotation($c0);
        $transform = self::mul(self::inv($c0Rotation), self::mul($delta, $c0Rotation));

        return [self::clean($c0), self::clean(self::mul($c1, self::inv($transform)))];
    }

    private function collectPoses(DOMElement $item, array &$out): void
    {
        foreach ($this->children($item, 'Pose') as $pose) {
            $weight = $this->prop($pose, 'Weight');
            $cframe = $this->cframe($pose, 'CFrame');
            if ($cframe !== null && ($weight === null || (float) $weight > 0)) {
                $out[$this->name($pose)] = $cframe;
            }
            $this->collectPoses($pose, $out);
        }
    }

    private function sequence(string $name): ?DOMElement
    {
        $best = null;
        $bestFrames = -1;
        foreach ($this->xpath->query('//Item[@class="KeyframeSequence"]') as $sequence) {
            $frames = count($this->children($sequence, 'Keyframe'));
            if ($frames === 0) {
                continue;
            }
            if ($name !== '') {
                if (strcasecmp(trim($this->name($sequence)), trim($name)) === 0) {
                    return $sequence;
                }
                continue;
            }
            if ($best === null) {
                $best = $sequence;
                $bestFrames = $frames;
            }
        }

        return $best;
    }

    private function parts(DOMElement $model): array
    {
        $parts = [];
        foreach ($model->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'Item' && in_array($child->getAttribute('class'), ['Part', 'MeshPart'], true)) {
                $cframe = $this->cframe($child, 'CFrame');
                $name = $this->name($child);
                if ($cframe !== null && !isset($parts[$name])) {
                    $parts[$name] = $cframe;
                }
            }
        }

        return $parts;
    }

    private function motors(DOMElement $model): array
    {
        $motors = [];
        foreach ($this->xpath->query('.//Item[@class="Motor6D" or @class="Motor"]', $model) as $motor) {
            $motors[$this->name($motor)] = [$this->cframe($motor, 'C0'), $this->cframe($motor, 'C1')];
        }

        return $motors;
    }

    private function posed(array $joints): bool
    {
        return $this->score($joints) > 0.05;
    }

    private function score(array $joints): float
    {
        $score = 0.0;
        foreach ($joints as $joint => [$c0, $c1]) {
            [, , $d0, $d1] = self::R6[$joint];
            for ($i = 0; $i < 12; $i++) {
                $score += abs($c1[$i] - $d1[$i]) + abs($c0[$i] - $d0[$i]);
            }
        }

        return $score;
    }

    private function children(DOMElement $item, string $class): array
    {
        $out = [];
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'Item' && $child->getAttribute('class') === $class) {
                $out[] = $child;
            }
        }

        return $out;
    }

    private function properties(DOMElement $item): ?DOMElement
    {
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'Properties') {
                return $child;
            }
        }

        return null;
    }

    private function prop(DOMElement $item, string $name): ?string
    {
        $properties = $this->properties($item);
        if ($properties === null) {
            return null;
        }
        foreach ($properties->childNodes as $child) {
            if ($child instanceof DOMElement && $child->getAttribute('name') === $name) {
                return $child->textContent;
            }
        }

        return null;
    }

    private function name(DOMElement $item): string
    {
        return (string) $this->prop($item, 'Name');
    }

    private function cframe(DOMElement $item, string $name): ?array
    {
        $properties = $this->properties($item);
        if ($properties === null) {
            return null;
        }
        foreach ($properties->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'CoordinateFrame' && $child->getAttribute('name') === $name) {
                $values = [];
                foreach (['X', 'Y', 'Z', 'R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22'] as $key) {
                    $node = $child->getElementsByTagName($key)->item(0);
                    if ($node === null) {
                        return null;
                    }
                    $values[] = (float) $node->textContent;
                }

                return $values;
            }
        }

        return null;
    }

    private static function identity(): array
    {
        return [0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1];
    }

    private static function rotation(array $c): array
    {
        return array_merge([0, 0, 0], array_slice($c, 3));
    }

    private static function mul(array $a, array $b): array
    {
        $r = array_slice($a, 3);
        $s = array_slice($b, 3);
        $out = [
            $a[0] + $r[0] * $b[0] + $r[1] * $b[1] + $r[2] * $b[2],
            $a[1] + $r[3] * $b[0] + $r[4] * $b[1] + $r[5] * $b[2],
            $a[2] + $r[6] * $b[0] + $r[7] * $b[1] + $r[8] * $b[2],
        ];
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $out[] = $r[$i * 3] * $s[$j] + $r[$i * 3 + 1] * $s[3 + $j] + $r[$i * 3 + 2] * $s[6 + $j];
            }
        }

        return $out;
    }

    private static function inv(array $c): array
    {
        $r = array_slice($c, 3);
        $t = [$r[0], $r[3], $r[6], $r[1], $r[4], $r[7], $r[2], $r[5], $r[8]];

        return [
            -($t[0] * $c[0] + $t[1] * $c[1] + $t[2] * $c[2]),
            -($t[3] * $c[0] + $t[4] * $c[1] + $t[5] * $c[2]),
            -($t[6] * $c[0] + $t[7] * $c[1] + $t[8] * $c[2]),
            ...$t,
        ];
    }

    private static function clean(array $c): array
    {
        return array_map(fn ($v) => abs($v) < 1e-7 ? 0.0 : round($v, 7), $c);
    }

    private static function num(float $v): string
    {
        $text = rtrim(rtrim(sprintf('%.7F', $v), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
