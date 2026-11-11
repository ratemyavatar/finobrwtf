<?php

namespace App\Services;

use App\Services\ItemImport\RbxmReader;

/**
 * Makes a place file loadable by the 2012 client (0.65): it only reads XML (the binary format came in
 * 2013), and its Workspace already has a Terrain, so a newer file's own Terrain makes game:Load stop
 * with "Unable to change Terrain's parent". XML that doesn't need changing is returned as is.
 */
class Place2012
{
    public static function convert(string $data): string
    {
        if (!str_starts_with($data, '<roblox!')) {
            return $data;   // already XML (e.g. saved by 2012 Studio, whose Terrain loads fine)
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
