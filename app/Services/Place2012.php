<?php

namespace App\Services;

use App\Services\ItemImport\RbxmReader;

class Place2012
{
    public static function convert(string $data): string
    {
        if (!str_starts_with($data, '<roblox!')) {
            return $data;
        }

        $xml = RbxmReader::toXml($data);

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return $xml;
        }
        $xpath = new \DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//Item[@class="Terrain"]')) as $terrain) {
            $terrain->parentNode->removeChild($terrain);
        }
        return $doc->saveXML($doc->documentElement);
    }
}
