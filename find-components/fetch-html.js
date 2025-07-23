// find-components/fetch-html.js
const puppeteer = require('puppeteer');
const fs = require('fs');

const [,, url, outFile] = process.argv;
if (!url || !outFile) {
    console.error('Usage: node fetch-html.js <url> <outFile>');
    process.exit(1);
}

(async () => {
    const browser = await puppeteer.launch({ headless: true });
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
    const html = await page.content();
    fs.writeFileSync(outFile, html);
    await browser.close();
    console.log(`Fetched HTML to ${outFile}`);
})();
