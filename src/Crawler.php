<?php
declare(strict_types=1);

namespace CryptCrawler;

use DOMDocument;
use DOMXPath;
use PDO;
use PDOException;
use Throwable;

final class Crawler
{
    /** @var string[] */
    private array $searchTerms = [
        'SB DUNK',
        'NIKE SB DUNK',
        'NIKE DUNK',
        'AIR JORDAN 1',
        'AIR JORDAN 2',
        'AIR JORDAN 3',
        'AIR JORDAN 4',
        'AIR JORDAN 5',
        'AIR JORDAN 6',
        'AIR JORDAN 7',
        'AIR JORDAN 8',
        'AIR JORDAN 9',
        'AIR JORDAN 10',
        'AIR JORDAN 11',
        'AIR JORDAN 12',
        'AIR JORDAN 13',
        'AIR JORDAN',
        'NIKE AIR JORDAN',
        'AIR MAX',
        'NIKE AIR MAX',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly ListingNormalizer $normalizer,
        private readonly BrowserHarvester $browserHarvester,
        private readonly string $sourceConfigPath
    ) {
    }

    public function runAll(): void
    {
        $pdo = $this->database->pdo();
        $configRaw = file_get_contents($this->sourceConfigPath);
        if ($configRaw === false) {
            throw new \RuntimeException('Unable to read config/sources.json');
        }
        $config = json_decode($configRaw, true);
        if (!is_array($config) || !isset($config['sources']) || !is_array($config['sources'])) {
            throw new \RuntimeException('Invalid sources.json');
        }

        foreach ($config['sources'] as $sourceDef) {
            if (!is_array($sourceDef)) {
                continue;
            }
            $sourceName = (string)($sourceDef['name'] ?? '');
            $enabled = (bool)($sourceDef['enabled'] ?? true);
            if (!$enabled || $sourceName === '') {
                continue;
            }

            foreach ($this->searchTerms as $term) {
                if ($this->shouldStop($pdo)) {
                    $this->setStopped($pdo);
                    return;
                }

                $target = $this->normalizer->targetForTerm($term);
                $method = ($sourceName === 'Vinted') ? 'PHP cURL page crawl' : 'Browser scroll';
                $this->upsertProgressRow($pdo, $sourceName, $term, $method, $target, 'running');
                $this->updateCurrentState($pdo, 'running', $sourceName, $term, $method, null);

                try {
                    if ($sourceName === 'Vinted') {
                        $this->crawlVinted($sourceDef, $term, $target);
                    } else {
                        $this->crawlBrowserSource($sourceDef, $term, $target);
                    }
                    $this->markProgressDone($pdo, $sourceName, $term, 'done');
                } catch (Throwable $e) {
                    error_log('[CryptCrawler] ' . $e->getMessage());
                    $this->incrementProgress($pdo, $sourceName, $term, 'failed');
                    $this->markProgressDone($pdo, $sourceName, $term, 'failed');
                }
            }

        }

        $this->updateCurrentState($pdo, 'idle', null, null, null, null);
    }

    private function crawlVinted(array $sourceDef, string $term, int $target): void
    {
        $pdo = $this->database->pdo();
        $sourceName = (string)$sourceDef['name'];
        $maxPages = (int)($sourceDef['page_limit'] ?? 35);
        $template = (string)($sourceDef['url_template'] ?? '');

        for ($page = 1; $page <= $maxPages; $page++) {
            if ($this->shouldStop($pdo) || $this->accepted($pdo, $sourceName, $term) >= $target) {
                return;
            }

            $url = str_replace(
                ['{query}', '{page}'],
                [rawurlencode($term), (string)$page],
                $template
            );
            $html = $this->fetchHtml($url);
            if ($html === null || $html === '') {
                $this->incrementProgress($pdo, $sourceName, $term, 'failed');
                continue;
            }

            $items = $this->extractVintedListings($html, $sourceName, $term);
            if (!$items) {
                continue;
            }

            foreach ($items as $item) {
                if ($this->shouldStop($pdo) || $this->accepted($pdo, $sourceName, $term) >= $target) {
                    return;
                }
                $result = $this->ingestListing($item, $term, $sourceName);
                $this->incrementProgress($pdo, $sourceName, $term, $result);
            }
        }

    }

    private function crawlBrowserSource(array $sourceDef, string $term, int $target): void
    {
        $pdo = $this->database->pdo();
        $sourceName = (string)$sourceDef['name'];
        $template = (string)($sourceDef['url_template'] ?? '');
        $maxScrolls = (int)($sourceDef['max_scrolls'] ?? 55);
        $url = str_replace('{query}', rawurlencode($term), $template);

        $this->browserHarvester->run(
            $sourceName,
            $term,
            $url,
            $maxScrolls,
            function (array $listing) use ($pdo, $sourceName, $term, $target): void {
                if ($this->accepted($pdo, $sourceName, $term) >= $target) {
                    return;
                }
                $result = $this->ingestListing($listing, $term, $sourceName);
                $this->incrementProgress($pdo, $sourceName, $term, $result);
            },
            function (array $progress) use ($pdo, $sourceName, $term): void {
                $this->updateCurrentState(
                    $pdo,
                    'running',
                    $sourceName,
                    $term,
                    'Browser scroll',
                    null
                );
            },
            function () use ($pdo, $sourceName, $term, $target): bool {
                if ($this->shouldStop($pdo)) {
                    return true;
                }
                return $this->accepted($pdo, $sourceName, $term) >= $target;
            }
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function extractVintedListings(string $html, string $sourceName, string $term): array
    {
        $items = [];

        if (preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/is', $html, $nextData)) {
            $decoded = json_decode(trim((string)($nextData[1] ?? '')), true);
            if (is_array($decoded)) {
                $items = array_merge($items, $this->extractFromNestedJson($decoded, $sourceName, $term));
            }
        }

        if (preg_match_all('/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonChunk) {
                $decoded = json_decode(trim($jsonChunk), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $items = array_merge($items, $this->extractFromStructuredData($decoded, $sourceName, $term));
            }
        }

        if (!empty($items)) {
            return $items;
        }

        if (!function_exists('libxml_use_internal_errors')) {
            return [];
        }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $anchors = $xpath->query('//a[contains(@href, "/items/")]');

        if ($anchors === false) {
            return [];
        }

        foreach ($anchors as $a) {
            $href = (string)$a->getAttribute('href');
            if ($href === '') {
                continue;
            }
            if (!str_starts_with($href, 'http')) {
                $href = 'https://www.vinted.com' . $href;
            }
            $title = trim($a->textContent ?? '');
            $imgNode = $xpath->query('.//img', $a)->item(0);
            $img = $imgNode ? (string)$imgNode->getAttribute('src') : '';
            $rawBlock = trim($a->parentNode?->textContent ?? '');
            $price = 0.0;
            if (preg_match('/\$?\s*([0-9]+(?:\.[0-9]{1,2})?)/', str_replace(',', '', $rawBlock), $m)) {
                $price = (float)$m[1];
            }

            $items[] = [
                'image_url' => $img,
                'title' => $title !== '' ? $title : $term,
                'description' => '',
                'price' => $price,
                'size' => null,
                'source' => $sourceName,
                'url' => $href,
            ];
        }

        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function extractFromStructuredData(array $decoded, string $sourceName, string $term): array
    {
        $items = [];
        if (($decoded['@type'] ?? '') === 'ItemList' && isset($decoded['itemListElement']) && is_array($decoded['itemListElement'])) {
            foreach ($decoded['itemListElement'] as $entry) {
                $obj = $entry['item'] ?? $entry;
                if (!is_array($obj)) {
                    continue;
                }
                $priceRaw = $obj['offers']['price'] ?? 0;
                $items[] = [
                    'image_url' => is_array($obj['image'] ?? null) ? ((string)($obj['image'][0] ?? '')) : (string)($obj['image'] ?? ''),
                    'title' => (string)($obj['name'] ?? $term),
                    'description' => (string)($obj['description'] ?? ''),
                    'price' => $priceRaw,
                    'size' => null,
                    'source' => $sourceName,
                    'url' => (string)($obj['url'] ?? ''),
                ];
            }
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function extractFromNestedJson(array $payload, string $sourceName, string $term): array
    {
        $out = [];
        $walker = function (mixed $node) use (&$walker, &$out, $sourceName, $term): void {
            if (!is_array($node)) {
                return;
            }
            $url = $node['url'] ?? null;
            $title = $node['title'] ?? ($node['name'] ?? null);
            $price = $node['price'] ?? ($node['total_item_price'] ?? 0);
            if (is_string($url) && $url !== '' && is_string($title) && $title !== '') {
                $img = '';
                if (isset($node['photo']) && is_array($node['photo'])) {
                    $img = (string)($node['photo']['url'] ?? '');
                }
                if ($img === '' && isset($node['photos']) && is_array($node['photos'])) {
                    $first = $node['photos'][0] ?? null;
                    if (is_array($first)) {
                        $img = (string)($first['url'] ?? '');
                    }
                }
                $out[] = [
                    'image_url' => $img,
                    'title' => $title,
                    'description' => (string)($node['description'] ?? ''),
                    'price' => $price,
                    'size' => isset($node['size']) ? (string)$node['size'] : null,
                    'source' => $sourceName,
                    'url' => str_starts_with($url, 'http') ? $url : ('https://www.vinted.com' . $url),
                ];
            }
            foreach ($node as $child) {
                $walker($child);
            }
        };
        $walker($payload);
        return $out;
    }

    private function fetchHtml(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension is required.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CryptCrawler/1.0');
        $output = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($output === false || $code >= 400) {
            return null;
        }
        return (string)$output;
    }

    private function ingestListing(array $raw, string $searchTerm, string $sourceName): string
    {
        $pdo = $this->database->pdo();

        $title = $this->normalizer->cleanText((string)($raw['title'] ?? ''));
        $description = $this->normalizer->cleanText((string)($raw['description'] ?? ''));
        $url = $this->normalizer->cleanText((string)($raw['url'] ?? ''));
        if ($title === '' || $url === '') {
            return 'failed';
        }

        if ($this->normalizer->shouldReject($searchTerm, $title, $description)) {
            return 'rejected_filtered';
        }

        $canonicalUrl = $this->normalizer->normalizeCanonicalUrl($url, $sourceName);
        if ($canonicalUrl === '') {
            return 'failed';
        }

        $price = $this->normalizer->normalizePrice($raw['price'] ?? 0);
        $size = $this->normalizer->deriveSize(
            isset($raw['size']) ? (string)$raw['size'] : null,
            $title,
            $description
        );
        $category = $this->normalizer->mapCategory($searchTerm);
        $imageUrl = $this->normalizer->cleanText((string)($raw['image_url'] ?? ''));

        try {
            $stmt = $pdo->prepare("
                INSERT INTO listings (image_url, title, description, price, size, source, url, canonical_url, category, search_term, created_at, updated_at)
                VALUES (:image_url, :title, :description, :price, :size, :source, :url, :canonical_url, :category, :search_term, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':image_url' => $imageUrl !== '' ? $imageUrl : null,
                ':title' => $title,
                ':description' => $description !== '' ? $description : null,
                ':price' => $price,
                ':size' => $size,
                ':source' => $sourceName,
                ':url' => $url,
                ':canonical_url' => $canonicalUrl,
                ':category' => $category,
                ':search_term' => $searchTerm,
            ]);
            return 'accepted';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'duplicates_skipped';
            }
            error_log('[CryptCrawler ingest] ' . $e->getMessage());
            return 'failed';
        }
    }

    private function shouldStop(PDO $pdo): bool
    {
        $row = $pdo->query("SELECT stop_requested FROM crawl_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        return ((int)($row['stop_requested'] ?? 0)) === 1;
    }

    private function setStopped(PDO $pdo): void
    {
        $pdo->exec("UPDATE crawl_state SET status = 'idle', stop_requested = 0, current_source = NULL, current_search_term = NULL, current_method = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
    }

    private function updateCurrentState(PDO $pdo, string $status, ?string $source, ?string $term, ?string $method, ?string $error): void
    {
        $stmt = $pdo->prepare("
            UPDATE crawl_state
            SET status = :status,
                current_source = :source,
                current_search_term = :search_term,
                current_method = :method,
                last_error = :last_error,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
        ");
        $stmt->execute([
            ':status' => $status,
            ':source' => $source,
            ':search_term' => $term,
            ':method' => $method,
            ':last_error' => $error,
        ]);
    }

    private function upsertProgressRow(PDO $pdo, string $source, string $term, string $method, int $target, string $status): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO crawl_progress (source, search_term, method, target, accepted, duplicates_skipped, rejected_filtered, failed, status, updated_at)
            VALUES (:source, :search_term, :method, :target, 0, 0, 0, 0, :status, CURRENT_TIMESTAMP)
            ON CONFLICT(source, search_term) DO UPDATE SET
                method = excluded.method,
                target = excluded.target,
                status = excluded.status,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':source' => $source,
            ':search_term' => $term,
            ':method' => $method,
            ':target' => $target,
            ':status' => $status,
        ]);
    }

    private function markProgressDone(PDO $pdo, string $source, string $term, string $status): void
    {
        $stmt = $pdo->prepare("UPDATE crawl_progress SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE source = :source AND search_term = :search_term");
        $stmt->execute([
            ':status' => $status,
            ':source' => $source,
            ':search_term' => $term,
        ]);
    }

    private function incrementProgress(PDO $pdo, string $source, string $term, string $column): void
    {
        if (!in_array($column, ['accepted', 'duplicates_skipped', 'rejected_filtered', 'failed'], true)) {
            return;
        }
        $sql = "UPDATE crawl_progress SET {$column} = {$column} + 1, updated_at = CURRENT_TIMESTAMP WHERE source = :source AND search_term = :search_term";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':source' => $source,
            ':search_term' => $term,
        ]);
    }

    private function accepted(PDO $pdo, string $source, string $term): int
    {
        $stmt = $pdo->prepare("SELECT accepted FROM crawl_progress WHERE source = :source AND search_term = :search_term");
        $stmt->execute([
            ':source' => $source,
            ':search_term' => $term,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['accepted'] ?? 0);
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require_once __DIR__ . '/Database.php';
    require_once __DIR__ . '/ListingNormalizer.php';
    require_once __DIR__ . '/BrowserHarvester.php';

    $db = new Database(__DIR__ . '/../database.sqlite');
    $db->initialize();
    $pdo = $db->pdo();

    try {
        $crawler = new Crawler(
            $db,
            new ListingNormalizer(),
            new BrowserHarvester(__DIR__ . '/../tools/browser-harvester.js'),
            __DIR__ . '/../config/sources.json'
        );
        $crawler->runAll();
    } catch (Throwable $e) {
        error_log('[CryptCrawler worker] ' . $e->getMessage());
        $stmt = $pdo->prepare("
            UPDATE crawl_state
            SET status = 'idle',
                stop_requested = 0,
                current_source = NULL,
                current_search_term = NULL,
                current_method = NULL,
                last_error = :error,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
        ");
        $stmt->execute([':error' => $e->getMessage()]);
    }
}
