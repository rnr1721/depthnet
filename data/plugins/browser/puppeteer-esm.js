import puppeteer from 'puppeteer';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));

const config = {{CONFIG}};
const params = {{PARAMS}};

(async () => {
    let browser = null;
    try {
        browser = await puppeteer.launch({
            headless: config.headless,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-blink-features=AutomationControlled',
                '--exclude-switches=enable-automation',
                '--disable-web-security',
                '--disable-features=VizDisplayCompositor',
                '--no-first-run',
                '--disable-default-apps',
                '--disable-extensions-except',
                '--disable-plugins-discovery',
                '--disable-translate',
                '--disable-ipc-flooding-protection'
            ]
        });

        const page = await browser.newPage();
        
        await page.setViewport(config.viewport);
        await page.setUserAgent(config.userAgent);

        // Enhanced anti-detection
        await page.evaluateOnNewDocument(() => {
            // Remove webdriver property
            Object.defineProperty(navigator, 'webdriver', {
                get: () => undefined,
            });
            
            // Mock plugins
            Object.defineProperty(navigator, 'plugins', {
                get: () => [1, 2, 3, 4, 5],
            });
            
            // Mock languages
            Object.defineProperty(navigator, 'languages', {
                get: () => ['en-US', 'en'],
            });
            
            // Mock permissions (safely)
            try {
                const originalQuery = window.navigator.permissions.query;
                window.navigator.permissions.query = (parameters) => (
                    parameters.name === 'notifications' ?
                        Promise.resolve({ state: 'default' }) :
                        originalQuery(parameters)
                );
            } catch (e) {
                // Ignore if permissions API not available
            }
            
            // Hide automation indicators
            delete window.cdc_adoQpoasnfa76pfcZLmcfl_Array;
            delete window.cdc_adoQpoasnfa76pfcZLmcfl_Promise;
            delete window.cdc_adoQpoasnfa76pfcZLmcfl_Symbol;
        });

        if (!config.enableImages) {
            await page.setRequestInterception(true);
            page.on('request', req => {
                if (req.resourceType() === 'image') {
                    req.abort();
                } else {
                    req.continue();
                }
            });
        }

        await page.setJavaScriptEnabled(config.enableJavaScript);

        let result = { success: true };

        switch (params.action) {
            case 'test':
            case 'goto':
                await page.goto(params.url, { 
                    waitUntil: 'networkidle0',
                    timeout: params.timeout || config.timeout 
                });
                
                // Wait for page to be fully rendered
                await new Promise(resolve => setTimeout(resolve, 2000));
                
                // Diagnostic info
                const diagnostics = await page.evaluate(() => {
                    return {
                        readyState: document.readyState,
                        bodyExists: !!document.body,
                        bodyChildren: document.body ? document.body.children.length : 0,
                        bodyHTML: document.body ? document.body.innerHTML.substring(0, 200) : 'NO BODY',
                        documentHTML: document.documentElement ? document.documentElement.outerHTML.substring(0, 200) : 'NO DOC',
                        scripts: document.scripts.length,
                        hasJavaScript: typeof window.jQuery !== 'undefined' || typeof window.React !== 'undefined' || typeof window.Vue !== 'undefined'
                    };
                });
                
                // Wait for any content to appear in body
                try {
                    await page.waitForFunction(() => {
                        return document.body && document.body.children.length > 0;
                    }, { timeout: 10000 });
                } catch (e) {
                    // If no content appears, still continue
                }
                
                result.title = await page.title();
                result.finalUrl = page.url();
                result.diagnostics = diagnostics;
                break;

            case 'open_page':
                await page.goto(params.url, { 
                    waitUntil: 'networkidle0',
                    timeout: params.timeout || config.timeout 
                });
                
                // Wait for page to be fully rendered
                await new Promise(resolve => setTimeout(resolve, 3000));
                
                // Wait for any content to appear in body
                try {
                    await page.waitForFunction(() => {
                        return document.body && document.body.children.length > 0;
                    }, { timeout: 10000 });
                } catch (e) {
                    // If no content appears, still continue
                }
                
                // Get all info at once
                result.title = await page.title();
                result.finalUrl = page.url();
                
                // Get diagnostics
                result.diagnostics = await page.evaluate(() => {
                    return {
                        readyState: document.readyState,
                        bodyExists: !!document.body,
                        bodyChildren: document.body ? document.body.children.length : 0,
                        scripts: document.scripts.length,
                        hasJavaScript: typeof window.jQuery !== 'undefined' || typeof window.React !== 'undefined' || typeof window.Vue !== 'undefined'
                    };
                });
                
                // Get only body content (no head)
                result.content = await page.evaluate((includeHead) => {
                    if (includeHead) {
                        return document.documentElement.outerHTML;
                    }
                    return document.body ? document.body.outerHTML : document.documentElement.outerHTML;
                }, params.includeHead || false);
                break;

            case 'get_content':
                await page.goto(params.url, { 
                    waitUntil: 'networkidle0',
                    timeout: params.timeout || config.timeout 
                });
                
                // Wait for content
                await new Promise(resolve => setTimeout(resolve, 2000));
                
                if (params.selector) {
                    const element = await page.$(params.selector);
                    result.content = element ? await page.evaluate(el => el.innerHTML, element) : '';
                } else {
                    // Get only body content
                    result.content = await page.evaluate((includeHead) => {
                        if (includeHead) {
                            return document.documentElement.outerHTML;
                        }
                        return document.body ? document.body.outerHTML : document.documentElement.outerHTML;
                    }, params.includeHead || false);
                }
                break;

            case 'click':
                await page.waitForSelector(params.selector, { timeout: params.timeout });
                await page.click(params.selector);
                break;

            case 'type':
                await page.waitForSelector(params.selector);
                await page.type(params.selector, params.text, { delay: params.delay });
                break;

            case 'wait':
                await page.waitForSelector(params.selector, { timeout: params.timeout });
                break;

            case 'screenshot':
                await page.screenshot({ 
                    path: params.path,
                    fullPage: params.fullPage 
                });
                break;

            case 'content':
                // Get detailed diagnostics first
                const contentDiagnostics = await page.evaluate(() => {
                    return {
                        readyState: document.readyState,
                        bodyExists: !!document.body,
                        bodyChildren: document.body ? document.body.children.length : 0,
                        bodyInnerHTML: document.body ? document.body.innerHTML.length : 0,
                        documentHTML: document.documentElement ? document.documentElement.outerHTML.length : 0,
                        scripts: document.scripts.length,
                        viewport: {
                            width: window.innerWidth,
                            height: window.innerHeight
                        },
                        url: window.location.href,
                        userAgent: navigator.userAgent
                    };
                });
                
                // Wait for dynamic content to load
                try {
                    // First wait for any content to appear
                    await page.waitForFunction(() => {
                        return document.body && (
                            document.body.children.length > 0 || 
                            document.body.innerHTML.trim().length > 0
                        );
                    }, { timeout: 10000 });
                    
                    // Additional wait for dynamic content
                    await new Promise(resolve => setTimeout(resolve, 2000));
                } catch (e) {
                    // Continue even if no content detected
                }
                
                if (params.selector) {
                    const element = await page.$(params.selector);
                    result.content = element ? await page.evaluate(el => el.innerHTML, element) : '';
                } else {
                    // Get only body content (cleaner for AI)
                    result.content = await page.evaluate((includeHead) => {
                        if (includeHead) {
                            return document.documentElement.outerHTML;
                        }
                        return document.body ? document.body.outerHTML : document.documentElement.outerHTML;
                    }, params.includeHead || false);
                }
                
                // Add diagnostics to result
                result.diagnostics = contentDiagnostics;
                break;

            case 'text':
                const element = await page.$(params.selector);
                result.text = element ? await page.evaluate(el => el.textContent, element) : '';
                break;

            case 'eval':
                result.result = await page.evaluate(params.code);
                break;

            case 'title':
                result.title = await page.title();
                break;

            case 'url':
                result.url = page.url();
                break;

            case 'close':
                // Browser will be closed in finally block
                break;

            default:
                throw new Error('Unknown action: ' + params.action);
        }

        console.log(JSON.stringify(result));

    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();