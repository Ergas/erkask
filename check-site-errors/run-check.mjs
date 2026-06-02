import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { XMLParser } from 'fast-xml-parser';

const DEFAULT_CONFIG = {
  source: {
    type: 'auto',
    url: '',
  },
  crawl: {
    maxPages: 100,
    sameDomainOnly: true,
    concurrency: 2,
    skipSlugs: [],
    sleepBetweenRequestsSeconds: 8,
    pageTimeoutMs: 30000,
    waitAfterLoadMs: 1500,
    sitemapTimeoutMs: 20000
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
};

let signalAbortRequested = false;

function toArray(value) {
  if (Array.isArray(value)) {
    return value;
  }
  if (value === undefined || value === null) {
    return [];
  }
  return [value];
}

function deepMerge(base, override) {
  if (Array.isArray(base) || Array.isArray(override)) {
    return override !== undefined ? override : base;
  }
  if (base && typeof base === 'object' && override && typeof override === 'object') {
    const merged = { ...base };
    for (const [key, value] of Object.entries(override)) {
      merged[key] = key in base ? deepMerge(base[key], value) : value;
    }
    return merged;
  }
  return override !== undefined ? override : base;
}

function coerceBoolean(value, fallback) {
  if (typeof value === 'boolean') {
    return value;
  }
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();
    if (['1', 'true', 'yes', 'on'].includes(normalized)) {
      return true;
    }
    if (['0', 'false', 'no', 'off'].includes(normalized)) {
      return false;
    }
  }
  return fallback;
}

function coerceInteger(value, fallback, minimum = 0) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  if (!Number.isFinite(parsed)) {
    return fallback;
  }
  return Math.max(minimum, parsed);
}

function coerceNumber(value, fallback, minimum = 0) {
  const parsed = Number.parseFloat(String(value ?? ''));
  if (!Number.isFinite(parsed)) {
    return fallback;
  }
  return Math.max(minimum, parsed);
}

function isHttpUrl(value) {
  return /^https?:\/\//i.test(String(value || ''));
}

function normalizeComparableHost(host) {
  return String(host || '').trim().toLowerCase().replace(/^www\./, '');
}

function isSameSiteUrl(candidateUrl, referenceUrl) {
  try {
    const candidate = new URL(candidateUrl);
    const reference = new URL(referenceUrl);
    return candidate.protocol === reference.protocol && normalizeComparableHost(candidate.host) === normalizeComparableHost(reference.host);
  } catch {
    return false;
  }
}

function stripFragment(url) {
  try {
    const parsed = new URL(url);
    parsed.hash = '';
    return parsed.href;
  } catch {
    return String(url || '');
  }
}

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function createRequestPacer(delayMs) {
  const normalizedDelayMs = Math.max(0, Math.round(delayMs));
  let lastRequestStartedAt = 0;
  let queue = Promise.resolve();

  return async function paceRequest() {
    const previous = queue;
    let release;
    queue = new Promise((resolve) => {
      release = resolve;
    });

    await previous;
    try {
      if (normalizedDelayMs <= 0) {
        lastRequestStartedAt = Date.now();
        return;
      }

      if (lastRequestStartedAt > 0) {
        const waitMs = Math.max(0, (lastRequestStartedAt + normalizedDelayMs) - Date.now());
        if (waitMs > 0) {
          await sleep(waitMs);
        }
      }
      lastRequestStartedAt = Date.now();
    } finally {
      release();
    }
  };
}

async function waitForRequestSlot(requestPacer) {
  if (typeof requestPacer === 'function') {
    await requestPacer();
  }
}

function normalizeFilterEntry(entry) {
  if (!entry) {
    return null;
  }

  if (typeof entry === 'string') {
    const trimmed = entry.trim();
    if (!trimmed) {
      return null;
    }
    if (trimmed.startsWith('regex:')) {
      return {
        type: 'regex',
        value: trimmed.slice(6).trim(),
        flags: ''
      };
    }
    if (trimmed.startsWith('exact:')) {
      return {
        type: 'exact',
        value: trimmed.slice(6).trim()
      };
    }
    return {
      type: 'substring',
      value: trimmed
    };
  }

  if (typeof entry === 'object' && typeof entry.value === 'string') {
    return {
      type: ['exact', 'substring', 'regex'].includes(entry.type) ? entry.type : 'substring',
      value: entry.value,
      flags: typeof entry.flags === 'string' ? entry.flags : '',
      target: typeof entry.target === 'string' ? entry.target : 'text'
    };
  }

  return null;
}

function normalizeFilterEntries(entries) {
  return toArray(entries)
    .map(normalizeFilterEntry)
    .filter(Boolean);
}

function normalizeSkipSlugEntry(entry) {
  const normalized = String(entry || '')
    .trim()
    .toLowerCase()
    .replace(/^\/+|\/+$/g, '');

  return normalized || null;
}

function normalizeSkipSlugs(entries) {
  return Array.from(new Set(
    toArray(entries)
      .flatMap((entry) => (typeof entry === 'string' ? entry.split(',') : [entry]))
      .map(normalizeSkipSlugEntry)
      .filter(Boolean)
  ));
}

function shouldSkipUrlBySlug(url, skipSlugs) {
  if (!Array.isArray(skipSlugs) || skipSlugs.length === 0) {
    return false;
  }

  try {
    const parsed = new URL(url);
    const normalizedPath = parsed.pathname
      .toLowerCase()
      .replace(/\/+/g, '/')
      .replace(/^\/+|\/+$/g, '');
    const pathSegments = normalizedPath === '' ? [] : normalizedPath.split('/');

    return skipSlugs.some((slug) => {
      if (!slug) {
        return false;
      }

      return normalizedPath === slug
        || normalizedPath.startsWith(`${slug}/`)
        || pathSegments.includes(slug);
    });
  } catch {
    return false;
  }
}

function getFilterCandidates(filter, payload) {
  if (filter.target === 'sourceUrl') {
    return [payload.location?.url || ''];
  }
  if (filter.target === 'level') {
    return [payload.level || ''];
  }
  if (filter.target === 'url') {
    return [payload.url || ''];
  }
  return [payload.text || ''];
}

function matchesFilter(filter, payload) {
  const candidates = getFilterCandidates(filter, payload);
  return candidates.some((candidate) => {
    const text = String(candidate || '');
    if (filter.type === 'exact') {
      return text === filter.value;
    }
    if (filter.type === 'regex') {
      try {
        return new RegExp(filter.value, filter.flags || '').test(text);
      } catch {
        return false;
      }
    }
    return text.includes(filter.value);
  });
}

function shouldIgnoreConsoleMessage(payload, filters) {
  return filters.some((filter) => matchesFilter(filter, payload));
}

function shouldIgnoreRequest(url, filters) {
  return filters.some((filter) => matchesFilter(filter, { url }));
}

function normalizeConfig(rawConfig, configPath) {
  const config = deepMerge(DEFAULT_CONFIG, rawConfig || {});
  const sourceUrl = String(config.source?.url || '').trim();
  const sourceType = String(config.source?.type || 'auto').trim().toLowerCase() || 'auto';
  const configDir = configPath ? path.dirname(configPath) : process.cwd();

  return {
    source: {
      type: sourceType,
      url: sourceUrl
    },
    crawl: {
      maxPages: coerceInteger(config.crawl?.maxPages, DEFAULT_CONFIG.crawl.maxPages, 1),
      sameDomainOnly: coerceBoolean(config.crawl?.sameDomainOnly, DEFAULT_CONFIG.crawl.sameDomainOnly),
      concurrency: Math.max(1, Math.min(10, coerceInteger(config.crawl?.concurrency, DEFAULT_CONFIG.crawl.concurrency, 1))),
      skipSlugs: normalizeSkipSlugs(config.crawl?.skipSlugs),
      sleepBetweenRequestsSeconds: coerceNumber(config.crawl?.sleepBetweenRequestsSeconds, DEFAULT_CONFIG.crawl.sleepBetweenRequestsSeconds, 0),
      pageTimeoutMs: coerceInteger(config.crawl?.pageTimeoutMs, DEFAULT_CONFIG.crawl.pageTimeoutMs, 1000),
      waitAfterLoadMs: coerceInteger(config.crawl?.waitAfterLoadMs, DEFAULT_CONFIG.crawl.waitAfterLoadMs, 0),
      sitemapTimeoutMs: coerceInteger(config.crawl?.sitemapTimeoutMs, DEFAULT_CONFIG.crawl.sitemapTimeoutMs, 1000)
    },
    browser: {
      headless: coerceBoolean(config.browser?.headless, DEFAULT_CONFIG.browser.headless),
      ignoreHTTPSErrors: coerceBoolean(config.browser?.ignoreHTTPSErrors, DEFAULT_CONFIG.browser.ignoreHTTPSErrors)
    },
    console: {
      includeErrors: coerceBoolean(config.console?.includeErrors, DEFAULT_CONFIG.console.includeErrors),
      includeWarnings: coerceBoolean(config.console?.includeWarnings, DEFAULT_CONFIG.console.includeWarnings),
      ignore: normalizeFilterEntries(config.console?.ignore)
    },
    requests: {
      trackFailedRequests: coerceBoolean(config.requests?.trackFailedRequests, DEFAULT_CONFIG.requests.trackFailedRequests),
      trackHttpErrors: coerceBoolean(config.requests?.trackHttpErrors, DEFAULT_CONFIG.requests.trackHttpErrors),
      ignoreResourceTypes: toArray(config.requests?.ignoreResourceTypes).map((item) => String(item || '').trim().toLowerCase()).filter(Boolean),
      ignoreUrlPatterns: normalizeFilterEntries(config.requests?.ignoreUrlPatterns)
    },
    output: {
      baseDir: configDir
    }
  };
}

export async function loadConfig(configPath) {
  const resolvedPath = path.resolve(configPath);
  const rawText = await fs.readFile(resolvedPath, 'utf8');
  const rawConfig = JSON.parse(rawText);
  const config = normalizeConfig(rawConfig, resolvedPath);

  if (!config.source.url) {
    throw new Error('Config must include source.url');
  }

  if (!isHttpUrl(config.source.url)) {
    throw new Error(`Source URL must start with http or https: ${config.source.url}`);
  }

  return config;
}

async function ensureDirectory(filePath) {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
}

async function readJsonFile(filePath) {
  try {
    const raw = await fs.readFile(filePath, 'utf8');
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

async function writeJsonFile(filePath, data) {
  await ensureDirectory(filePath);
  await fs.writeFile(filePath, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
}

async function fetchText(url, timeoutMs, requestPacer) {
  await waitForRequestSlot(requestPacer);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, {
      signal: controller.signal,
      redirect: 'follow',
      headers: {
        'user-agent': 'SiteErrorScannerBot/1.0'
      }
    });

    if (!response.ok) {
      throw new Error(`Failed to fetch ${url}: HTTP ${response.status}`);
    }

    return {
      text: await response.text(),
      finalUrl: response.url || url
    };
  } finally {
    clearTimeout(timeout);
  }
}

function extractXmlNodeText(node) {
  if (typeof node === 'string') {
    return node.trim();
  }
  if (typeof node === 'number' || typeof node === 'boolean') {
    return String(node).trim();
  }
  if (Array.isArray(node)) {
    for (const item of node) {
      const text = extractXmlNodeText(item);
      if (text !== '') {
        return text;
      }
    }
    return '';
  }
  if (node && typeof node === 'object') {
    if ('#text' in node) {
      return extractXmlNodeText(node['#text']);
    }
    for (const value of Object.values(node)) {
      const text = extractXmlNodeText(value);
      if (text !== '') {
        return text;
      }
    }
  }
  return '';
}

const sitemapParser = new XMLParser({
  ignoreAttributes: false,
  removeNSPrefix: true,
  trimValues: true,
  parseTagValue: true,
  parseAttributeValue: false
});

async function collectUrlsFromSitemap(sitemapUrl, timeoutMs, requestPacer, visited = new Set()) {
  const normalizedSitemapUrl = stripFragment(sitemapUrl);
  if (visited.has(normalizedSitemapUrl)) {
    return {
      urls: [],
      resolvedUrl: normalizedSitemapUrl
    };
  }
  visited.add(normalizedSitemapUrl);

  const { text: xmlText, finalUrl } = await fetchText(normalizedSitemapUrl, timeoutMs, requestPacer);
  const resolvedUrl = stripFragment(finalUrl || normalizedSitemapUrl);
  const parsed = sitemapParser.parse(xmlText);

  const sitemapEntries = toArray(parsed?.sitemapindex?.sitemap);
  if (sitemapEntries.length > 0) {
    const nestedResults = [];
    for (const entry of sitemapEntries) {
      const loc = extractXmlNodeText(entry?.loc);
      if (!isHttpUrl(loc)) {
        continue;
      }
      nestedResults.push(
        ...(await collectUrlsFromSitemap(new URL(loc, resolvedUrl).href, timeoutMs, requestPacer, visited)).urls
      );
    }
    return {
      urls: nestedResults,
      resolvedUrl
    };
  }

  const urlEntries = toArray(parsed?.urlset?.url);
  return {
    urls: urlEntries
      .map((entry) => extractXmlNodeText(entry?.loc))
      .filter(isHttpUrl)
      .map(stripFragment),
    resolvedUrl
  };
}

export async function getUrlsFromSource(config, options = {}) {
  const requestPacer = options.requestPacer;
  const sourceType = config.source.type === 'auto'
    ? (config.source.url.toLowerCase().endsWith('.xml') ? 'sitemap' : 'single')
    : config.source.type;

  let urls = [];
  let resolvedSourceUrl = config.source.url;
  if (sourceType === 'sitemap') {
    const sitemapResult = await collectUrlsFromSitemap(config.source.url, config.crawl.sitemapTimeoutMs, requestPacer);
    urls = sitemapResult.urls;
    resolvedSourceUrl = sitemapResult.resolvedUrl || config.source.url;
  } else if (sourceType === 'single') {
    urls = [stripFragment(config.source.url)];
  } else {
    throw new Error(`Unsupported source type: ${config.source.type}`);
  }

  const uniqueUrls = [];
  const seen = new Set();
  for (const url of urls) {
    if (!seen.has(url)) {
      seen.add(url);
      uniqueUrls.push(url);
    }
  }

  if (config.crawl.sameDomainOnly && uniqueUrls.length > 0) {
    urls = uniqueUrls.filter((url) => isSameSiteUrl(url, resolvedSourceUrl));
  } else {
    urls = uniqueUrls;
  }

  if (config.crawl.skipSlugs.length > 0) {
    urls = urls.filter((url) => !shouldSkipUrlBySlug(url, config.crawl.skipSlugs));
  }

  return urls.slice(0, config.crawl.maxPages);
}

function shouldRecordConsoleType(type, consoleConfig) {
  return (type === 'error' && consoleConfig.includeErrors) || (type === 'warning' && consoleConfig.includeWarnings);
}

function createIssueKey(issue) {
  return JSON.stringify([
    issue.category,
    issue.level || '',
    issue.text || '',
    issue.url || '',
    issue.resourceType || '',
    issue.status || '',
    issue.failureText || '',
    issue.location?.url || '',
    issue.location?.lineNumber || '',
    issue.location?.columnNumber || ''
  ]);
}

function dedupeIssues(issues) {
  const issueMap = new Map();

  for (const issue of issues) {
    const key = createIssueKey(issue);
    if (!issueMap.has(key)) {
      issueMap.set(key, { ...issue, occurrences: 1 });
      continue;
    }
    issueMap.get(key).occurrences += 1;
  }

  return Array.from(issueMap.values());
}

function createEmptySummary(totalPages) {
  return {
    pagesDiscovered: totalPages,
    pagesScanned: 0,
    pagesWithIssues: 0,
    pageHttpErrors: 0,
    pageNavigationErrors: 0,
    requestHttpErrors: 0,
    requestFailedErrors: 0,
    consoleErrors: 0,
    consoleWarnings: 0,
    pageErrors: 0,
    ignoredConsoleMessages: 0,
    ignoredRequestIssues: 0
  };
}

function addPageToSummary(summary, pageResult) {
  summary.pagesScanned += 1;
  if (pageResult.issues.length > 0) {
    summary.pagesWithIssues += 1;
  }
  summary.ignoredConsoleMessages += pageResult.ignoredConsoleMessages;
  summary.ignoredRequestIssues += pageResult.ignoredRequestIssues;

  for (const issue of pageResult.issues) {
    if (issue.category === 'page-http-error') {
      summary.pageHttpErrors += 1;
    } else if (issue.category === 'page-navigation-error') {
      summary.pageNavigationErrors += 1;
    } else if (issue.category === 'request-http-error') {
      summary.requestHttpErrors += 1;
    } else if (issue.category === 'request-failed') {
      summary.requestFailedErrors += 1;
    } else if (issue.category === 'console-error') {
      summary.consoleErrors += 1;
    } else if (issue.category === 'console-warning') {
      summary.consoleWarnings += 1;
    } else if (issue.category === 'pageerror') {
      summary.pageErrors += 1;
    }
  }
}

function buildPageIssueCount(issues) {
  const counts = {};
  for (const issue of issues) {
    counts[issue.category] = (counts[issue.category] || 0) + 1;
  }
  return counts;
}

async function scanPage(context, url, config, requestPacer) {
  const page = await context.newPage();
  page.setDefaultNavigationTimeout(config.crawl.pageTimeoutMs);
  page.setDefaultTimeout(config.crawl.pageTimeoutMs);

  const issues = [];
  let ignoredConsoleMessages = 0;
  let ignoredRequestIssues = 0;
  let mainStatus = null;
  let finalUrl = url;
  let pageTitle;
  let loadState = 'created';

  page.on('console', (msg) => {
    const type = msg.type();
    if (!shouldRecordConsoleType(type, config.console)) {
      return;
    }

    const payload = {
      level: type,
      text: msg.text(),
      location: msg.location()
    };

    if (shouldIgnoreConsoleMessage(payload, config.console.ignore)) {
      ignoredConsoleMessages += 1;
      return;
    }

    issues.push({
      category: type === 'warning' ? 'console-warning' : 'console-error',
      level: type,
      text: payload.text,
      location: payload.location
    });
  });

  page.on('pageerror', (error) => {
    const text = error instanceof Error ? error.message : String(error);
    const payload = {
      level: 'error',
      text,
      location: {
        url: page.url() || url
      }
    };

    if (shouldIgnoreConsoleMessage(payload, config.console.ignore)) {
      ignoredConsoleMessages += 1;
      return;
    }

    issues.push({
      category: 'pageerror',
      level: 'error',
      text,
      stack: error instanceof Error ? error.stack || '' : ''
    });
  });

  page.on('requestfailed', (request) => {
    if (!config.requests.trackFailedRequests) {
      return;
    }

    const requestUrl = request.url();
    const resourceType = String(request.resourceType() || '').toLowerCase();
    if (config.requests.ignoreResourceTypes.includes(resourceType) || shouldIgnoreRequest(requestUrl, config.requests.ignoreUrlPatterns)) {
      ignoredRequestIssues += 1;
      return;
    }

    issues.push({
      category: 'request-failed',
      url: requestUrl,
      resourceType,
      failureText: request.failure()?.errorText || 'unknown request failure'
    });
  });

  page.on('response', (response) => {
    if (!config.requests.trackHttpErrors) {
      return;
    }

    const status = response.status();
    if (status < 400) {
      return;
    }

    const request = response.request();
    const isMainNavigation = request.isNavigationRequest() && request.frame() === page.mainFrame();
    if (isMainNavigation) {
      return;
    }

    const responseUrl = response.url();
    const resourceType = String(request.resourceType() || '').toLowerCase();
    if (config.requests.ignoreResourceTypes.includes(resourceType) || shouldIgnoreRequest(responseUrl, config.requests.ignoreUrlPatterns)) {
      ignoredRequestIssues += 1;
      return;
    }

    issues.push({
      category: 'request-http-error',
      url: responseUrl,
      resourceType,
      status
    });
  });

  try {
    await waitForRequestSlot(requestPacer);
    const response = await page.goto(url, {
      waitUntil: 'domcontentloaded',
      timeout: config.crawl.pageTimeoutMs
    });

    loadState = 'domcontentloaded';
    mainStatus = response?.status() ?? null;
    finalUrl = page.url() || url;

    if (mainStatus !== null && mainStatus >= 400) {
      issues.push({
        category: 'page-http-error',
        url,
        status: mainStatus,
        finalUrl
      });
    }

    await page.waitForLoadState('load', {
      timeout: Math.min(config.crawl.pageTimeoutMs, 5000)
    }).then(() => {
      loadState = 'load';
    }).catch(() => undefined);

    await page.waitForLoadState('networkidle', {
      timeout: Math.min(config.crawl.pageTimeoutMs, 3000)
    }).then(() => {
      loadState = 'networkidle';
    }).catch(() => undefined);

    if (config.crawl.waitAfterLoadMs > 0) {
      await page.waitForTimeout(config.crawl.waitAfterLoadMs);
      loadState = `${loadState}+waited`;
    }
  } catch (error) {
    finalUrl = page.url() || url;
    issues.push({
      category: 'page-navigation-error',
      url,
      finalUrl,
      text: error instanceof Error ? error.message : String(error)
    });
  }

  try {
    pageTitle = await page.title();
  } catch {
    pageTitle = '';
  }

  const dedupedIssues = dedupeIssues(issues);
  await page.close();

  return {
    url,
    finalUrl,
    status: mainStatus,
    title: pageTitle,
    loadState,
    ignoredConsoleMessages,
    ignoredRequestIssues,
    issues: dedupedIssues,
    issueCounts: buildPageIssueCount(dedupedIssues)
  };
}

async function shouldAbort(progressPath) {
  if (signalAbortRequested) {
    return true;
  }
  if (!progressPath) {
    return false;
  }
  const data = await readJsonFile(progressPath);
  return Boolean(data?.abortRequested);
}

async function updateProgress(progressPath, updates) {
  if (!progressPath) {
    return;
  }

  const current = (await readJsonFile(progressPath)) || {};
  const next = {
    ...current,
    ...updates,
    abortRequested: Boolean(current.abortRequested || updates.abortRequested || signalAbortRequested)
  };
  await writeJsonFile(progressPath, next);
}

export async function runScan({ config, outputPath, progressPath, runId = 'manual-run' }) {
  if (!config || !outputPath) {
    throw new Error('runScan requires config and outputPath');
  }

  const startedAt = new Date().toISOString();
  const requestPacer = createRequestPacer(config.crawl.sleepBetweenRequestsSeconds * 1000);
  const urls = await getUrlsFromSource(config, { requestPacer });
  const summary = createEmptySummary(urls.length);
  const pageResults = new Array(urls.length);
  let processed = 0;
  let browser;
  let context;

  await updateProgress(progressPath, {
    runId,
    status: 'running',
    done: false,
    processed: 0,
    total: urls.length,
    currentUrl: null,
    startedAt,
    finishedAt: null,
    start_url: config.source.url
  });

  try {
    browser = await chromium.launch({
      headless: config.browser.headless
    });
    context = await browser.newContext({
      ignoreHTTPSErrors: config.browser.ignoreHTTPSErrors
    });

    let nextIndex = 0;
    const workerCount = Math.min(config.crawl.concurrency, Math.max(urls.length, 1));

    const worker = async () => {
      while (true) {
        if (await shouldAbort(progressPath)) {
          return;
        }

        const currentIndex = nextIndex;
        nextIndex += 1;
        if (currentIndex >= urls.length) {
          return;
        }

        const currentUrl = urls[currentIndex];
        await updateProgress(progressPath, {
          currentUrl,
          processed,
          total: urls.length,
          status: 'running'
        });

        const pageResult = await scanPage(context, currentUrl, config, requestPacer);
        pageResults[currentIndex] = pageResult;
        processed += 1;
        addPageToSummary(summary, pageResult);

        await updateProgress(progressPath, {
          currentUrl,
          processed,
          total: urls.length,
          status: 'running',
          pagesWithIssues: summary.pagesWithIssues
        });
      }
    };

    await Promise.all(Array.from({ length: workerCount }, () => worker()));

    const aborted = await shouldAbort(progressPath);
    const finishedAt = new Date().toISOString();
    const results = {
      runId,
      startedAt,
      finishedAt,
      status: aborted ? 'aborted' : 'finished',
      config,
      summary,
      pages: pageResults.filter(Boolean)
    };

    await writeJsonFile(outputPath, results);
    await updateProgress(progressPath, {
      runId,
      status: results.status,
      done: true,
      processed,
      total: urls.length,
      currentUrl: null,
      pagesWithIssues: summary.pagesWithIssues,
      finishedAt
    });

    return results;
  } catch (error) {
    const finishedAt = new Date().toISOString();
    await updateProgress(progressPath, {
      runId,
      status: 'failed',
      done: true,
      processed,
      total: urls.length,
      currentUrl: null,
      error: error instanceof Error ? error.message : String(error),
      finishedAt
    });
    throw error;
  } finally {
    await context?.close().catch(() => undefined);
    await browser?.close().catch(() => undefined);
  }
}

function parseArgs(argv) {
  const args = {};
  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index];
    if (!token.startsWith('--')) {
      continue;
    }

    const [rawKey, rawValue] = token.split('=', 2);
    const key = rawKey.slice(2);
    if (rawValue !== undefined) {
      args[key] = rawValue;
      continue;
    }

    const nextValue = argv[index + 1];
    if (nextValue && !nextValue.startsWith('--')) {
      args[key] = nextValue;
      index += 1;
    } else {
      args[key] = true;
    }
  }
  return args;
}

export async function cli(argv = process.argv.slice(2)) {
  const args = parseArgs(argv);
  if (!args.config) {
    throw new Error('Usage: node run-check.mjs --config <file> --output <file> [--progress <file>] [--run-id <id>]');
  }

  const config = await loadConfig(args.config);
  const outputPath = path.resolve(args.output || path.join(process.cwd(), `site-errors-${Date.now()}.json`));
  const progressPath = args.progress ? path.resolve(args.progress) : null;
  const runId = String(args['run-id'] || path.basename(outputPath, path.extname(outputPath)));
  return runScan({ config, outputPath, progressPath, runId });
}

process.on('SIGTERM', () => {
  signalAbortRequested = true;
});

process.on('SIGINT', () => {
  signalAbortRequested = true;
});

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  cli().catch((error) => {
    console.error(error instanceof Error ? error.stack || error.message : String(error));
    process.exitCode = 1;
  });
}

