<?php
declare(strict_types=1);

use CryptCrawler\Database;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/ListingNormalizer.php';
require_once __DIR__ . '/../src/BrowserHarvester.php';
require_once __DIR__ . '/../src/Crawler.php';

if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $fullPath = __DIR__ . $path;
    if ($path !== '/' && is_file($fullPath) && basename($fullPath) !== 'router.php') {
        return false;
    }
}

$db = new Database(__DIR__ . '/../database.sqlite');
$db->initialize();
$pdo = $db->pdo();

$action = $_GET['action'] ?? null;

if ($action === null) {
    require __DIR__ . '/index.php';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {
        case 'start':
            $state = $pdo->query("SELECT status FROM crawl_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            if (($state['status'] ?? '') === 'running') {
                echo json_encode(['ok' => true, 'message' => 'Crawl already running.']);
                break;
            }

            $pdo->exec("DELETE FROM crawl_progress");
            $stmt = $pdo->prepare("UPDATE crawl_state SET status = 'running', stop_requested = 0, last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
            $stmt->execute();

            $phpBinary = PHP_BINARY;
            $worker = realpath(__DIR__ . '/../src/Crawler.php');
            if ($worker === false) {
                throw new RuntimeException('Missing crawl worker script.');
            }

            if (!function_exists('popen') || !function_exists('pclose')) {
                throw new RuntimeException('popen/pclose are required to start background crawl.');
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $cmd = 'start /B "" ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($worker);
            } else {
                $cmd = 'nohup ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &';
            }
            $handle = popen($cmd, 'r');
            if ($handle === false) {
                throw new RuntimeException('Failed to launch background crawl process.');
            }
            pclose($handle);

            echo json_encode(['ok' => true, 'message' => 'Crawl started.']);
            break;

        case 'stop':
            $stmt = $pdo->prepare("UPDATE crawl_state SET stop_requested = 1, status = 'stopping', updated_at = CURRENT_TIMESTAMP WHERE id = 1");
            $stmt->execute();
            echo json_encode(['ok' => true, 'message' => 'Stop requested.']);
            break;

        case 'progress':
            $state = $pdo->query("SELECT status, stop_requested, current_source, current_search_term, current_method, last_error, updated_at FROM crawl_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            $progress = $pdo->query("SELECT source, search_term, method, target, accepted, duplicates_skipped, rejected_filtered, failed, status, updated_at FROM crawl_progress ORDER BY source, search_term")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'state' => $state, 'progress' => $progress]);
            break;

        case 'filters':
            $sizes = $pdo->query("SELECT DISTINCT size FROM listings WHERE size IS NOT NULL AND TRIM(size) <> '' ORDER BY size")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            echo json_encode([
                'ok' => true,
                'sources' => ['Vinted', 'Depop', 'Poshmark', 'Mercari'],
                'categories' => ['All', 'All Dunks', 'All SB', 'All Jordans', 'All Max'],
                'sizes' => $sizes,
            ]);
            break;

        case 'clear':
            $pdo->exec("DELETE FROM listings");
            $pdo->exec("DELETE FROM crawl_progress");
            $pdo->exec("UPDATE crawl_state SET status = 'idle', stop_requested = 0, current_source = NULL, current_search_term = NULL, current_method = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
            echo json_encode(['ok' => true, 'message' => 'All listings cleared.']);
            break;

        case 'listings':
            $source = trim((string)($_GET['source'] ?? ''));
            $category = trim((string)($_GET['category'] ?? 'All'));
            $size = trim((string)($_GET['size'] ?? ''));
            $sort = trim((string)($_GET['sort'] ?? 'price'));
            $dir = strtoupper(trim((string)($_GET['dir'] ?? 'ASC'))) === 'DESC' ? 'DESC' : 'ASC';

            $where = [];
            $params = [];

            if ($source !== '' && in_array($source, ['Vinted', 'Depop', 'Poshmark', 'Mercari'], true)) {
                $where[] = 'l.source = :source';
                $params[':source'] = $source;
            }
            if ($category !== '' && $category !== 'All') {
                $where[] = 'l.category = :category';
                $params[':category'] = $category;
            }
            if ($size !== '') {
                $where[] = 'l.size = :size';
                $params[':size'] = $size;
            }

            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $orderSql = 'ORDER BY l.price ' . $dir . ', l.title ASC';
            if ($sort === 'alpha') {
                $orderSql = 'ORDER BY l.title ' . $dir . ', l.price ASC';
            }

            $sql = "
                SELECT l.id, l.image_url, l.title, l.description, l.price, l.size, l.source, l.url, l.canonical_url, l.category, l.search_term, l.created_at, l.updated_at
                FROM listings l
                INNER JOIN (
                    SELECT canonical_url, MIN(id) AS min_id
                    FROM listings
                    GROUP BY canonical_url
                ) d ON d.min_id = l.id
                {$whereSql}
                {$orderSql}
                LIMIT 2000
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'items' => $rows]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('[CryptCrawler router] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
