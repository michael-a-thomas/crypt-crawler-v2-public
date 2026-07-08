# Crypt Crawler (PHP 8+ + SQLite, local only)

Crypt Crawler is a local sneaker listing crawler that aggregates listings from:
- Vinted (PHP cURL paginated crawl)
- Depop, Poshmark, Mercari (Node + Puppeteer browser scroll)

No frameworks, SQLite only, local only.

## 1) Requirements

- PHP 8+
- Node.js 18+ (recommended)
- npm

## 2) Install Node + Puppeteer dependencies

From the project root:

```bash
npm install
```

## 3) Start PHP server (exact command)

```bash
php -S 127.0.0.1:8787 -t public public/router.php
```

Open:

`http://127.0.0.1:8787`

## 4) Run browser harvester directly (manual)

Example:

```bash
node tools/browser-harvester.js --source=Depop --term="SB DUNK" --url="https://www.depop.com/search/?q=SB%20DUNK&sort=newly_listed" --scrolls=5 --wait=2000
```

Run all browser-scroll sources + terms automatically from config:

```bash
npm run browser-harvester:all
```

It prints JSON lines (listing/progress events) to stdout.

## 5) Trigger full crawl

From the UI:
- Click **Start Crawl**
- Progress panel updates near-real-time
- Click **Stop Crawl** to request graceful stop
- Click **Clear All Listings** to wipe data

Or via API:

```bash
curl "http://127.0.0.1:8787/?action=start"
curl "http://127.0.0.1:8787/?action=progress"
curl "http://127.0.0.1:8787/?action=stop"
curl "http://127.0.0.1:8787/?action=clear"
```

## Notes

- SQLite DB file is created at `database.sqlite` in the project root.
- Source configuration is in `config/sources.json`.
- The app deduplicates on `canonical_url` (UNIQUE) and also applies secondary dedup in dashboard query path.
