<?php
// Test sitemap functionality
echo "Testing BigBank.lt sitemap...\n\n";

function getUrlsFromSitemap($sitemapUrl) {
    $urls = [];
    $xmlContent = @file_get_contents($sitemapUrl);
    if ($xmlContent === false) {
        echo "❌ Failed to fetch sitemap: $sitemapUrl\n";
        return [];
    }
    echo "✓ Fetched sitemap (" . strlen($xmlContent) . " bytes)\n";

    $reader = new XMLReader();
    if (!$reader->XML($xmlContent)) {
        echo "❌ Failed to parse XML\n";
        return [];
    }

    $isIndex = false;
    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT) {
            $localName = $reader->localName;
            if ($localName === 'sitemapindex') {
                $isIndex = true;
                echo "  → Sitemap index detected\n";
            }
            if ($isIndex && $localName === 'sitemap') {
                while ($reader->read()) {
                    if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'loc') {
                        $loc = $reader->readString();
                        echo "  → Child sitemap: $loc\n";
                        $urls = array_merge($urls, getUrlsFromSitemap($loc));
                        break;
                    }
                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'sitemap') break;
                }
            } elseif (!$isIndex && $localName === 'url') {
                while ($reader->read()) {
                    if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'loc') {
                        $urls[] = $reader->readString();
                        break;
                    }
                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'url') break;
                }
            }
        }
    }
    $reader->close();
    return $urls;
}

$sitemapUrl = "https://www.bigbank.lt/sitemap.xml";
echo "Fetching sitemap: $sitemapUrl\n";
$urls = getUrlsFromSitemap($sitemapUrl);

echo "\n✓ Total URLs found: " . count($urls) . "\n\n";

if (count($urls) > 0) {
    echo "First 10 URLs:\n";
    foreach (array_slice($urls, 0, 10) as $i => $url) {
        echo "  " . ($i+1) . ". $url\n";
    }

    // Check if our test URL is in there
    $testUrl = "https://www.bigbank.lt/indeliai/taupomuju-ir-terminuotuju-indeliu-palyginimas/";
    if (in_array($testUrl, $urls)) {
        echo "\n✓ Test URL IS in sitemap!\n";
    } else {
        echo "\n⚠ Test URL NOT in sitemap\n";
        echo "Searching for similar URLs...\n";
        foreach ($urls as $url) {
            if (strpos($url, 'indeliai') !== false) {
                echo "  Found: $url\n";
            }
        }
    }
} else {
    echo "❌ No URLs found in sitemap!\n";
}

