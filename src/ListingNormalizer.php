<?php
declare(strict_types=1);

namespace CryptCrawler;

final class ListingNormalizer
{
    public function mapCategory(string $searchTerm): string
    {
        $t = strtoupper(trim($searchTerm));
        if (str_contains($t, 'DUNK')) {
            return str_contains($t, 'SB') ? 'All SB' : 'All Dunks';
        }
        if (str_contains($t, 'JORDAN')) {
            return 'All Jordans';
        }
        if (str_contains($t, 'MAX')) {
            return 'All Max';
        }
        return 'All';
    }

    public function isJordanTerm(string $searchTerm): bool
    {
        $t = strtoupper($searchTerm);
        return str_contains($t, 'JORDAN');
    }

    public function targetForTerm(string $searchTerm): int
    {
        return $this->isJordanTerm($searchTerm) ? 50 : 100;
    }

    public function shouldReject(string $searchTerm, string $title, string $description): bool
    {
        $text = trim($title . ' ' . $description);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        if ($haystack === '') {
            return false;
        }
        if (str_contains($haystack, 'toddler')) {
            return true;
        }
        if ($this->isJordanTerm($searchTerm)) {
            foreach (['shorts', 'card', 'autograph', 'jersey'] as $bad) {
                if (str_contains($haystack, $bad)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function normalizeCanonicalUrl(string $url, string $source): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return strtolower($url);
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = strtolower((string)($parts['path'] ?? ''));
        $path = preg_replace('#/+$#', '', $path ?? '') ?? $path;
        $sourceLower = strtolower($source);

        if ($sourceLower === 'vinted') {
            if (preg_match('#/items/(\d+)#', $path, $m)) {
                $path = '/items/' . $m[1];
            }
        } elseif ($sourceLower === 'depop') {
            if (preg_match('#/@[^/]+/[^/]+/([^/?#]+)#', $path, $m)) {
                $path = '/item/' . $m[1];
            }
        } elseif ($sourceLower === 'mercari') {
            if (preg_match('#/item/([a-z0-9]+)#', $path, $m)) {
                $path = '/item/' . $m[1];
            }
        } elseif ($sourceLower === 'poshmark') {
            if (preg_match('#/listing/([^/?#]+)#', $path, $m)) {
                $path = '/listing/' . $m[1];
            }
        }

        return $scheme . '://' . $host . $path;
    }

    public function normalizePrice(mixed $price): float
    {
        if (is_float($price) || is_int($price)) {
            return (float)$price;
        }
        $raw = (string)$price;
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', str_replace(',', '', $raw), $m)) {
            return (float)$m[1];
        }
        return 0.0;
    }

    public function deriveSize(?string $size, string $title, string $description): ?string
    {
        $candidate = trim((string)$size);
        if ($candidate !== '') {
            return $candidate;
        }

        $combined = $title . ' ' . $description;
        if (preg_match('/\b(?:size|sz)\s*[:#]?\s*(\d{1,2}(?:\.\d)?)\b/i', $combined, $m)) {
            return $m[1];
        }
        return null;
    }

    public function cleanText(?string $text): string
    {
        $value = trim((string)$text);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, 1000) : substr($value, 0, 1000);
    }
}
