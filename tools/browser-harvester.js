#!/usr/bin/env node
'use strict';

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

function parseArgs(argv) {
  const out = {};
  for (const part of argv) {
    if (!part.startsWith('--')) continue;
    const idx = part.indexOf('=');
    if (idx === -1) {
      out[part.slice(2)] = true;
    } else {
      out[part.slice(2, idx)] = part.slice(idx + 1);
    }
  }
  return out;
}

function emit(obj) {
  process.stdout.write(JSON.stringify(obj) + '\n');
}

function toAbs(url, href) {
  if (!href) return '';
  if (/^https?:\/\//i.test(href)) return href;
  try {
    return new URL(href, url).toString();
  } catch (e) {
    return '';
  }
}

(async () => {
  const args = parseArgs(process.argv.slice(2));
  const maxScrolls = Number(args.scrolls || 50);
  const waitMs = Number(args.wait || 2000);

  const searchTerms = [
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
    'NIKE AIR MAX'
  ];

  const jobs = [];
  const oneSource = String(args.source || '');
  const oneTerm = String(args.term || '');
  const oneUrl = String(args.url || '');

  if (oneSource && oneTerm && oneUrl) {
    jobs.push({ source: oneSource, term: oneTerm, url: oneUrl });
  } else if (args.config) {
    const configPath = path.resolve(String(args.config));
    const raw = fs.readFileSync(configPath, 'utf8');
    const cfg = JSON.parse(raw);
    const sources = Array.isArray(cfg.sources) ? cfg.sources : [];
    for (const sourceDef of sources) {
      if (!sourceDef || sourceDef.enabled === false || sourceDef.method !== 'browser_scroll') continue;
      const sourceName = String(sourceDef.name || '');
      const template = String(sourceDef.url_template || '');
      if (!sourceName || !template) continue;
      for (const term of searchTerms) {
        jobs.push({
          source: sourceName,
          term,
          url: template.replace('{query}', encodeURIComponent(term))
        });
      }
    }
  } else {
    emit({ type: 'error', error: 'Missing required args. Use --source --term --url or --config' });
    process.exit(2);
  }

  const browser = await puppeteer.launch({ headless: true });

  async function runJob(source, term, url) {
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    const seen = new Set();
    let discovered = 0;

    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

    for (let step = 1; step <= maxScrolls; step++) {
      await page.waitForTimeout(waitMs);
      const rows = await page.evaluate(({ source, baseUrl }) => {
        const sourceLower = source.toLowerCase();
        const list = [];
        const anchors = Array.from(document.querySelectorAll('a[href]'));

        for (const a of anchors) {
          const hrefRaw = a.getAttribute('href') || '';
          const href = hrefRaw.trim();
          if (!href) continue;

          let accepted = false;
          if (sourceLower === 'depop') accepted = href.includes('/products/') || href.includes('/item/');
          if (sourceLower === 'poshmark') accepted = href.includes('/listing/');
          if (sourceLower === 'mercari') accepted = href.includes('/item/');
          if (!accepted) continue;

          const card = a.closest('article,li,div') || a;
          const text = (card && card.textContent ? card.textContent : a.textContent || '').replace(/\s+/g, ' ').trim();
          if (!text) continue;

          const titleNode = card.querySelector('h1,h2,h3,h4,[data-testid*="title"],[class*="title"]');
          const title = (titleNode ? titleNode.textContent : a.getAttribute('aria-label') || text).replace(/\s+/g, ' ').trim();

          const priceMatch = text.replace(/,/g, '').match(/\$ ?([0-9]+(?:\.[0-9]{1,2})?)/);
          const price = priceMatch ? Number(priceMatch[1]) : 0;
          const img = card.querySelector('img');
          const imageUrl = img ? (img.getAttribute('src') || img.getAttribute('data-src') || '') : '';
          const descNode = card.querySelector('p,[data-testid*="description"],[class*="description"]');
          const description = descNode ? descNode.textContent.replace(/\s+/g, ' ').trim() : '';
          const sizeMatch = text.match(/\b(?:size|sz)\s*[:#]?\s*(\d{1,2}(?:\.\d)?)\b/i);
          const size = sizeMatch ? sizeMatch[1] : null;

          list.push({
            url: href,
            title,
            description,
            price,
            image_url: imageUrl,
            size
          });
        }

        return list.map((v) => {
          try {
            v.url = new URL(v.url, baseUrl).toString();
          } catch (e) {
            v.url = '';
          }
          return v;
        });
      }, { source, baseUrl: url });

      const batch = [];
      for (const row of rows) {
        if (!row || !row.url || !row.title) continue;
        const key = row.url.toLowerCase().split('?')[0];
        if (seen.has(key)) continue;
        seen.add(key);
        discovered++;
        batch.push(row);
      }
      if (batch.length > 0) {
        emit({ type: 'batch', source, term, method: 'Browser scroll', data: batch });
      }

      emit({
        type: 'progress',
        source,
        term,
        method: 'Browser scroll',
        scroll_step: step,
        discovered
      });

      await page.evaluate(() => {
        window.scrollTo(0, document.body.scrollHeight);
      });
    }

    emit({ type: 'done', source, term, discovered });
    await page.close();
  }

  try {
    for (const job of jobs) {
      await runJob(job.source, job.term, job.url);
    }
    await browser.close();
    process.exit(0);
  } catch (err) {
    emit({
      type: 'error',
      source: oneSource || null,
      term: oneTerm || null,
      error: (err && err.message) ? err.message : String(err)
    });
    try { await browser.close(); } catch (e) {}
    process.exit(1);
  }
})();
