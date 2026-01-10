const puppeteer = require('puppeteer');
const fs = require('fs');

(async () => {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--ignore-certificate-errors']
  });
  const page = await browser.newPage();
  
  // Set viewport to a reasonable size
  await page.setViewport({width: 1280, height: 1024});

  try {
    console.log('Navigating to login...');
    await page.goto('https://makehaven-website.lndo.site/user/login', {waitUntil: 'networkidle2'});

    console.log('Logging in...');
    await page.type('#edit-name', 'makehaven');
    await page.type('#edit-pass', 'temp123');
    await Promise.all([
      page.click('#edit-submit'),
      page.waitForNavigation({waitUntil: 'networkidle2'}),
    ]);

    console.log('Navigating to YIR page...');
    await page.goto('https://makehaven-website.lndo.site/user/5391/year-in-review', {waitUntil: 'networkidle2'});
    
    // Screenshot
    await page.screenshot({path: 'yir_screenshot.png', fullPage: true});
    console.log('Screenshot saved to yir_screenshot.png');

    // Extract Chart Config
    // We look for the drupalSettings where the chart might be defined or the SVG itself
    // Actually, let's dump the page content to grep it later
    const content = await page.content();
    fs.writeFileSync('yir_page_source.html', content);
    console.log('Page source saved to yir_page_source.html');

  } catch (e) {
    console.error('Error:', e);
  } finally {
    await browser.close();
  }
})();
