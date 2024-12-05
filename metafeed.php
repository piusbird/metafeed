<?php
error_reporting(E_ERROR | E_PARSE);
class RSSFeedAggregator
{
    private $sourceFeedsUrls = [];
    private $mergedItems = [];
    private $channelInfo = [];

    /**
     * Constructor to set source feed URLs
     * @param array $feedUrls List of RSS feed URLs to aggregate
     */
    public function __construct(array $feedUrls)
    {
        $this->sourceFeedsUrls = $feedUrls;
    }

    /**
     * Fetch and parse all source RSS feeds
     * @throws Exception If there's an issue fetching or parsing feeds
     */
    public function aggregateFeeds()
    {
        foreach ($this->sourceFeedsUrls as $feedUrl) {
            try {
                $xmlContent = $this->fetchFeedContent($feedUrl);
                $this->parseFeed($xmlContent);
            } catch (Exception $e) {
                // Log error but continue processing other feeds
                printf("%s\n", 'Error processing feed {$feedUrl}: ' . $e->getMessage());
                exit();
            }
        }

        // Sort merged items by publication date (most recent first)
        usort($this->mergedItems, function ($a, $b) {
            return strtotime($b['pubDate']) - strtotime($a['pubDate']);
        });
    }

    /**
     * Fetch feed content using cURL
     * @param string $url Feed URL
     * @return string XML content
     * @throws Exception If fetching fails
     */
    private function fetchFeedContent(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $xmlContent = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("cURL Error: " . curl_error($ch));
        }

        curl_close($ch);
        return $xmlContent;
    }

    /**
     * Parse individual feed and extract items
     * @param string $xmlContent RSS or Atom feed XML
     */
    private function parseFeed(string $xmlContent)
    {
        $xml = new SimpleXMLElement($xmlContent);

        // Detect feed type and parse accordingly
        $namespaces = $xml->getNamespaces(true);

        if (isset($xml->channel)) {
            // RSS Feed
            $this->parseRSSFeed($xml);
        } elseif (isset($namespaces['atom']) || $xml->getName() === 'feed') {
            // Atom Feed
            $this->parseAtomFeed($xml, $namespaces);
        } else {
            throw new Exception("Unsupported feed format");
        }
    }

    /**
     * Parse RSS feed
     * @param SimpleXMLElement $xml RSS XML
     */
    private function parseRSSFeed(SimpleXMLElement $xml)
    {
        // Capture channel info from first feed
        if (empty($this->channelInfo)) {
            $this->channelInfo = [
                'title' => (string) $xml->channel->title,
                'description' => (string) $xml->channel->description,
                'link' => (string) $xml->channel->link
            ];
        }

        // Extract and store RSS feed items
        foreach ($xml->channel->item as $item) {
            $this->mergedItems[] = [
                'title' => (string) $item->title,
                'link' => (string) $item->link,
                'description' => (string) $item->description,
                'pubDate' => (string) $item->pubDate ?? date('r'),
                'guid' => (string) $item->guid
            ];
        }
    }

    /**
     * Parse Atom feed
     * @param SimpleXMLElement $xml Atom XML
     * @param array $namespaces XML namespaces
     */
    private function parseAtomFeed(SimpleXMLElement $xml, array $namespaces)
    {
        // Capture feed info from first feed
        if (empty($this->channelInfo)) {
            $this->channelInfo = [
                'title' => (string) $xml->title,
                'description' => (string) $xml->subtitle,
                'link' => (string) $xml->link['href']
            ];
        }

        // Extract and store Atom feed items
        $atomNamespace = $namespaces['atom'] ?? null;
        $items = $atomNamespace
            ? $xml->children($atomNamespace)->entry
            : $xml->entry;

        foreach ($items as $entry) {
            // Determine link (some Atom feeds have multiple links)
            $link = '';
            foreach ($entry->link as $linkElement) {
                if ((string) $linkElement['rel'] === 'alternate' || !isset($linkElement['rel'])) {
                    $link = (string) $linkElement['href'];
                    break;
                }
            }

            // Get publication date (try multiple formats)
            $pubDate = '';
            $dateTags = ['published', 'updated'];
            foreach ($dateTags as $dateTag) {
                if (!empty($entry->{$dateTag})) {
                    $pubDate = (string) $entry->{$dateTag};
                    break;
                }
            }

            $this->mergedItems[] = [
                'title' => (string) $entry->title,
                'link' => $link,
                'description' => (string) $entry->content ?? (string) $entry->summary,
                'pubDate' => $pubDate ?? date('r'),
                'guid' => (string) $entry->id
            ];
        }
    }
    /**
     * Generate and output merged RSS feed
     * @param int $limitItems Maximum number of items to include (optional)
     */
    public function outputMergedFeed(int $limitItems = 50)
    {
        // Limit number of items
        $outputItems = array_slice($this->mergedItems, 0, $limitItems);
        $outputFeed = "";
        // Start XML output

        $outputFeed .= '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $outputFeed .= '<rss version="2.0">' . PHP_EOL;
        $outputFeed .= '<?xml-stylesheet href="/style.xsl" type="text/xsl"?>' . PHP_EOL;
        $outputFeed .= '<channel>' . PHP_EOL;

        // Channel information
        $outputFeed .= '<title>' . 'Pius Metafeed' . '</title>' . PHP_EOL;
        $outputFeed .= '<description>' . 'All the hoots fit to toot' . '</description>' . PHP_EOL;
        $outputFeed .= '<link>' . 'https://piusbird.space' . '</link>' . PHP_EOL;

        // Output feed items
        foreach ($outputItems as $item) {
            $outputFeed .= '<item>' . PHP_EOL;
            $outputFeed .= '  <title>' . htmlspecialchars($item['title']) . '</title>' . PHP_EOL;
            $outputFeed .= '  <link>' . htmlspecialchars($item['link']) . '</link>' . PHP_EOL;
            $outputFeed .= '  <description>' . htmlspecialchars($item['description']) . '</description>' . PHP_EOL;
            $outputFeed .= '  <pubDate>' . htmlspecialchars($item['pubDate']) . '</pubDate>' . PHP_EOL;
            $outputFeed .= '  <guid>' . htmlspecialchars($item['guid']) . '</guid>' . PHP_EOL;
            $outputFeed .= '</item>' . PHP_EOL;
        }

        $outputFeed .= '</channel>' . PHP_EOL;
        $outputFeed .= '</rss>';
        return $outputFeed;
    }

    /**
     * Get total merged items count
     * @return int Number of merged items
     */
    public function getTotalItemsCount(): int
    {
        return count($this->mergedItems);
    }
}

// Example Usage
function refresh_feed()
{
    try {

        $feedUrls = [
            'https://treefort.piusbird.space/rss.xml',
            'https://nightsong.piusbird.space/feed',
            'https://thegnuguru.org/rss',
            'https://tilde.zone/@piusbird.rss',
            'https://tilde.town/~piusbird/technomancer/feed.rss'


        ];

        $aggregator = new RSSFeedAggregator($feedUrls);
        $aggregator->aggregateFeeds();
        $out = $aggregator->outputMergedFeed(750);
        $fp = fopen("cache.xml", "w");
        fwrite($fp, $out);
        fclose($fp);
        echo $out;



    } catch (Exception $e) {
        // Handle any errors during feed aggregation
        header('HTTP/1.1 500 Internal Server Error');
        echo "Feed Aggregation Error: " . $e->getMessage();
    }

}
$cacheFile = "cache.xml";
header('Content-Type: application/rss+xml; charset=utf-8');
$st = stat($cacheFile);
if (!$st) {
    refresh_feed();
} else {

    $file_modified_time = new DateTime('@' . filemtime($cacheFile));
    $current_time = new DateTime();
    $cacheTTL = $current_time->modify('-2 hours');

    if ($file_modified_time < $cacheTTL) {
        refresh_feed();
    } else {
        $h = fopen($cacheFile, 'r');
        $out = fread($h, filesize($cacheFile));
        fclose($h);
        echo $out;
    }
}