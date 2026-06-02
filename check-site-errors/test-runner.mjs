import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import fs from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import { getUrlsFromSource, loadConfig, runScan } from './run-check.mjs';

const execFileAsync = promisify(execFile);

function createTestServer() {
  const documentPaths = new Set([
    '/healthy',
    '/console-error',
    '/warning',
    '/page-error',
    '/resource-error',
    '/ignored-console',
    '/missing-page'
  ]);
  const requestLog = [];

  const server = http.createServer((request, response) => {
    const url = new URL(request.url || '/', 'http://127.0.0.1');
    const pathname = url.pathname;

    if (documentPaths.has(pathname)) {
      requestLog.push({ pathname, at: Date.now() });
    }

    if (pathname === '/sitemap.xml') {
      const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>http://127.0.0.1:${server.address().port}/healthy</loc></url>
  <url><loc>http://127.0.0.1:${server.address().port}/console-error</loc></url>
  <url><loc>http://127.0.0.1:${server.address().port}/warning</loc></url>
  <url><loc>http://127.0.0.1:${server.address().port}/page-error</loc></url>
  <url><loc>http://127.0.0.1:${server.address().port}/resource-error</loc></url>
  <url><loc>http://127.0.0.1:${server.address().port}/ignored-console</loc></url>
  <url><loc>http://127.0.0.1:${server.address().port}/missing-page</loc></url>
</urlset>`;
      response.writeHead(200, { 'Content-Type': 'application/xml; charset=utf-8' });
      response.end(body);
      return;
    }

    if (pathname === '/missing-script.js') {
      response.writeHead(404, { 'Content-Type': 'application/javascript; charset=utf-8' });
      response.end('console.log("missing script");');
      return;
    }

    if (pathname === '/missing-page') {
      response.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
      response.end('<!doctype html><title>Missing</title><h1>Missing page</h1>');
      return;
    }

    const pageMap = {
      '/healthy': '<!doctype html><title>Healthy</title><h1>Healthy</h1>',
      '/console-error': '<!doctype html><title>Console Error</title><script>console.error("Visible console error");</script>',
      '/warning': '<!doctype html><title>Warning</title><script>console.warn("Visible warning");</script>',
      '/page-error': '<!doctype html><title>Page Error</title><script>setTimeout(() => { throw new Error("Visible page error"); }, 50);</script>',
      '/resource-error': '<!doctype html><title>Resource Error</title><script src="/missing-script.js"></script>',
      '/ignored-console': '<!doctype html><title>Ignored Console</title><script>console.error("ResizeObserver loop limit exceeded");</script>'
    };

    if (pageMap[pathname]) {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      response.end(pageMap[pathname]);
      return;
    }

    response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end('Not found');
  });

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      server.clearRequestLog = () => {
        requestLog.length = 0;
      };
      server.getDocumentRequestLog = () => requestLog.slice();
      resolve(server);
    });
  });
}

function findPage(results, urlPart) {
  return (results.pages || []).find((page) => String(page.url || '').includes(urlPart));
}

async function writeConfig(tempDir, port, includeWarnings, suffix, sleepBetweenRequestsSeconds = 8, skipSlugs = []) {
  const configPath = path.join(tempDir, `config-${suffix}.json`);
  const config = {
    source: {
      type: 'sitemap',
      url: `http://127.0.0.1:${port}/sitemap.xml`
    },
    crawl: {
      maxPages: 20,
      sameDomainOnly: true,
      concurrency: 2,
      skipSlugs,
      sleepBetweenRequestsSeconds,
      pageTimeoutMs: 5000,
      waitAfterLoadMs: 250,
      sitemapTimeoutMs: 5000
    },
    browser: {
      headless: true,
      ignoreHTTPSErrors: true
    },
    console: {
      includeErrors: true,
      includeWarnings,
      ignore: [
        {
          type: 'substring',
          value: 'ResizeObserver loop limit exceeded'
        }
      ]
    },
    requests: {
      trackFailedRequests: true,
      trackHttpErrors: true,
      ignoreResourceTypes: [],
      ignoreUrlPatterns: []
    }
  };
  await fs.writeFile(configPath, `${JSON.stringify(config, null, 2)}\n`, 'utf8');
  return configPath;
}

async function runOneScan(tempDir, port, suffix, includeWarnings, sleepBetweenRequestsSeconds = 8, skipSlugs = []) {
  const configPath = await writeConfig(tempDir, port, includeWarnings, suffix, sleepBetweenRequestsSeconds, skipSlugs);
  const config = await loadConfig(configPath);
  const outputPath = path.join(tempDir, `output-${suffix}.json`);
  const progressPath = path.join(tempDir, `progress-${suffix}.json`);
  return runScan({ config, outputPath, progressPath, runId: suffix });
}

async function runOneScanViaCli(tempDir, port, suffix, includeWarnings, sleepBetweenRequestsSeconds = 8, skipSlugs = []) {
  const configPath = await writeConfig(tempDir, port, includeWarnings, suffix, sleepBetweenRequestsSeconds, skipSlugs);
  const outputPath = path.join(tempDir, `output-${suffix}.json`);
  const progressPath = path.join(tempDir, `progress-${suffix}.json`);
  await execFileAsync(process.execPath, [
    path.join(process.cwd(), 'check-site-errors/run-check.mjs'),
    '--config',
    configPath,
    '--output',
    outputPath,
    '--progress',
    progressPath,
    '--run-id',
    suffix
  ], {
    cwd: process.cwd()
  });

  return JSON.parse(await fs.readFile(outputPath, 'utf8'));
}

async function main() {
  const tempDir = await fs.mkdtemp(path.join(os.tmpdir(), 'site-error-scanner-'));
  const server = await createTestServer();
  const port = server.address().port;

  try {
    const defaultConfigPath = path.join(tempDir, 'config-defaults.json');
    await fs.writeFile(defaultConfigPath, `${JSON.stringify({
      source: {
        type: 'single',
        url: `http://127.0.0.1:${port}/healthy`
      }
    }, null, 2)}\n`, 'utf8');
    const defaultConfig = await loadConfig(defaultConfigPath);
    assert.equal(defaultConfig.crawl.sleepBetweenRequestsSeconds, 8);

    const resultsWithoutWarnings = await runOneScan(tempDir, port, 'errors-only', false);
    assert.equal(resultsWithoutWarnings.status, 'finished');
    assert.equal(resultsWithoutWarnings.summary.pagesScanned, 7);

    const consoleErrorPage = findPage(resultsWithoutWarnings, '/console-error');
    assert.ok(consoleErrorPage);
    assert.ok(consoleErrorPage.issues.some((issue) => issue.category === 'console-error' && String(issue.text).includes('Visible console error')));

    const warningPageWithoutWarnings = findPage(resultsWithoutWarnings, '/warning');
    assert.ok(warningPageWithoutWarnings);
    assert.ok(!warningPageWithoutWarnings.issues.some((issue) => issue.category === 'console-warning'));

    const pageErrorPage = findPage(resultsWithoutWarnings, '/page-error');
    assert.ok(pageErrorPage.issues.some((issue) => issue.category === 'pageerror' && String(issue.text).includes('Visible page error')));

    const resourceErrorPage = findPage(resultsWithoutWarnings, '/resource-error');
    assert.ok(resourceErrorPage.issues.some((issue) => issue.category === 'request-http-error' && String(issue.url).includes('/missing-script.js') && issue.status === 404));

    const missingPage = findPage(resultsWithoutWarnings, '/missing-page');
    assert.ok(missingPage.issues.some((issue) => issue.category === 'page-http-error' && issue.status === 404));

    const ignoredConsolePage = findPage(resultsWithoutWarnings, '/ignored-console');
    assert.ok(ignoredConsolePage);
    assert.ok(!ignoredConsolePage.issues.some((issue) => String(issue.text || '').includes('ResizeObserver loop limit exceeded')));
    assert.ok(resultsWithoutWarnings.summary.ignoredConsoleMessages >= 1);

    server.clearRequestLog();
    const pacedResults = await runOneScan(tempDir, port, 'paced', false, 0.2);
    assert.equal(pacedResults.config.crawl.sleepBetweenRequestsSeconds, 0.2);
    const documentRequestLog = server.getDocumentRequestLog();
    assert.ok(documentRequestLog.length >= 7);
    const gaps = documentRequestLog
      .slice(1)
      .map((entry, index) => entry.at - documentRequestLog[index].at);
    assert.ok(gaps.every((gap) => gap >= 150), `Expected request gaps >= 150ms, got: ${gaps.join(', ')}`);

    const resultsWithWarnings = await runOneScanViaCli(tempDir, port, 'with-warnings', true);
    const warningPageWithWarnings = findPage(resultsWithWarnings, '/warning');
    assert.ok(warningPageWithWarnings.issues.some((issue) => issue.category === 'console-warning' && String(issue.text).includes('Visible warning')));

    const resultsWithSkippedSlugs = await runOneScan(tempDir, port, 'skip-slugs', false, 0, ['warning', 'missing-page']);
    assert.equal(resultsWithSkippedSlugs.summary.pagesDiscovered, 5);
    assert.equal(resultsWithSkippedSlugs.summary.pagesScanned, 5);
    assert.equal(findPage(resultsWithSkippedSlugs, '/warning'), undefined);
    assert.equal(findPage(resultsWithSkippedSlugs, '/missing-page'), undefined);

    const redirectConfigPath = path.join(tempDir, 'config-redirect-sitemap.json');
    await fs.writeFile(redirectConfigPath, `${JSON.stringify({
      source: {
        type: 'sitemap',
        url: 'https://bigbank.ee/sitemap.xml'
      },
      crawl: {
        maxPages: 10,
        sameDomainOnly: true,
        concurrency: 1,
        sleepBetweenRequestsSeconds: 9,
        pageTimeoutMs: 5000,
        waitAfterLoadMs: 0,
        sitemapTimeoutMs: 5000
      },
      browser: {
        headless: true,
        ignoreHTTPSErrors: true
      },
      console: {
        includeErrors: true,
        includeWarnings: false,
        ignore: []
      },
      requests: {
        trackFailedRequests: true,
        trackHttpErrors: true,
        ignoreResourceTypes: [],
        ignoreUrlPatterns: []
      }
    }, null, 2)}\n`, 'utf8');

    const redirectConfig = await loadConfig(redirectConfigPath);
    const originalFetch = global.fetch;
    global.fetch = async (url, options = {}) => {
      const requestedUrl = String(url);
      if (requestedUrl === 'https://bigbank.ee/sitemap.xml') {
        return new Response(`<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://www.bigbank.ee/</loc></url>
  <url><loc>https://www.bigbank.ee/test-page/</loc></url>
</urlset>`, {
          status: 200,
          headers: { 'Content-Type': 'application/xml; charset=utf-8' }
        });
      }
      return originalFetch(url, options);
    };

    try {
      const redirectUrls = await getUrlsFromSource(redirectConfig);
      assert.deepEqual(redirectUrls, [
        'https://www.bigbank.ee/',
        'https://www.bigbank.ee/test-page/'
      ]);
    } finally {
      global.fetch = originalFetch;
    }

    console.log('Site error scanner tests passed.');
  } finally {
    await new Promise((resolve) => server.close(resolve));
    await fs.rm(tempDir, { recursive: true, force: true });
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.stack || error.message : String(error));
  process.exitCode = 1;
});

