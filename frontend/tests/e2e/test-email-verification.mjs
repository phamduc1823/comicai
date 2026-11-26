import puppeteer from 'puppeteer-core';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { startE2EServers, stopE2EServers, executePhpCode } from './creator-test-setup.mjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const SCREENSHOTS_DIR = path.resolve(__dirname, '..', '..', '..', 'AI', 'reports', 'screenshots');
const CHROME_PATH = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE_URL = process.env.E2E_BASE_URL || 'http://127.0.0.1:5173';
const LARAVEL_LOG = path.resolve(__dirname, '..', '..', '..', 'backend', 'storage', 'logs', 'laravel.log');

if (!fs.existsSync(SCREENSHOTS_DIR)) {
  fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}

async function clearAndType(page, selector, text) {
  await page.waitForSelector(selector, { timeout: 10000 });
  await page.click(selector);
  await page.keyboard.down('Control');
  await page.keyboard.press('KeyA');
  await page.keyboard.up('Control');
  await page.keyboard.press('Backspace');
  if (text) {
    await page.type(selector, text);
  }
}

async function runTest() {
  console.log('=== TEST LIVE EMAIL VERIFICATION UI & FLOW ===');
  let browser = null;

  try {
    // 1. Ensure user reader_unverified@comicai.test exists and is unverified
    executePhpCode(`
      $u = App\\Models\\User::updateOrCreate(
        ['email' => 'reader_unverified@comicai.test'],
        [
          'name' => 'Reader Unverified',
          'password' => Illuminate\\Support\\Facades\\Hash::make('Password123!'),
          'is_active' => true,
          'email_verified_at' => null,
        ]
      );
      $u->role = 'reader';
      $u->email_verified_at = null;
      $u->save();
      Illuminate\\Support\\Facades\\DB::table('cache')->truncate();
    `);

    const initialLogSize = fs.existsSync(LARAVEL_LOG) ? fs.statSync(LARAVEL_LOG).size : 0;

    await startE2EServers();

    browser = await puppeteer.launch({
      executablePath: CHROME_PATH,
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,800'],
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });

    page.on('console', msg => console.log('  [Browser Console]', msg.text()));
    page.on('pageerror', err => console.log('  [Browser Error]', err.message));
    page.on('response', async res => {
      if (res.url().includes('/login') || res.url().includes('/verification') || res.url().includes('/creator-applications')) {
        try {
          console.log(`  [HTTP ${res.status()}] ${res.url()} => ${(await res.text()).slice(0, 200)}`);
        } catch {}
      }
    });

    // 2. Login
    console.log('[1] Logging in as reader_unverified@comicai.test ...');
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle2' });
    await clearAndType(page, '#login-email', 'reader_unverified@comicai.test');
    await clearAndType(page, '#login-password', 'Password123!');
    await page.click('button.submit-btn');
    await page.waitForFunction(() => window.location.pathname !== '/login', { timeout: 15000 });
    console.log('    Login successful. Current path:', await page.evaluate(() => window.location.pathname));

    // 3. Visit /become-creator
    console.log('[2] Navigating to /become-creator ...');
    await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('.guard-unverified', { timeout: 10000 });
    console.log('    Guard card displayed: Email Chưa Được Xác Minh.');

    // 4. Click resend button
    console.log('[3] Clicking "Gửi lại email xác minh ✉️" ...');
    const resendBtn = await page.$('.guard-unverified button.btn-primary');
    if (!resendBtn) throw new Error('Resend verification button not found in .guard-unverified');
    await resendBtn.click();

    // Wait for success alert
    await page.waitForSelector('.alert-success', { timeout: 10000 });
    const successMsg = await page.$eval('.alert-success', el => el.textContent.trim());
    console.log(`    Success alert visible: "${successMsg}"`);

    // 5. Inspect laravel.log to get signed verification URL
    console.log('[4] Inspecting laravel.log for signed verification URL ...');
    await new Promise(r => setTimeout(r, 1500));
    const logContent = fs.readFileSync(LARAVEL_LOG, 'utf8');

    const matches = [...logContent.matchAll(/href="(https?:\/\/[^"]+\/api\/email\/verify\/[^"]+)"/g)];
    if (!matches || matches.length === 0) {
      throw new Error('Verification URL not found in laravel.log!');
    }
    const lastMatch = matches[matches.length - 1];
    const verificationUrl = lastMatch[1].replace(/&amp;/g, '&');
    console.log(`    Found signed verification URL: ${verificationUrl}`);

    // 6. Navigate to verification link
    console.log('[5] Opening verification link in browser ...');
    await page.goto(verificationUrl, { waitUntil: 'networkidle2' });

    // 7. Verify redirection and form unlocked
    console.log('[6] Verifying redirection and unlocked form ...');
    await page.waitForSelector('.creator-app-page', { timeout: 12000 });
    await page.waitForSelector('.form-card', { timeout: 12000 });
    await page.waitForSelector('#pen_name', { timeout: 12000 });

    const bannerText = await page.$eval('.alert-success', el => el.textContent.trim());
    console.log(`    Banner visible: "${bannerText}"`);

    // Take screenshot
    const screenshotPath = path.join(SCREENSHOTS_DIR, 'FlowA_03_Email_Verified_Live.png');
    await page.screenshot({ path: screenshotPath, fullPage: true });
    console.log(`    Screenshot saved to ${screenshotPath}`);

    // 8. Verify database
    const verifyResult = executePhpCode(`
      $u = App\\Models\\User::where('email', 'reader_unverified@comicai.test')->first();
      echo $u->email_verified_at ? 'VERIFIED_AT_' . $u->email_verified_at->toIso8601String() : 'NOT_VERIFIED';
    `);
    console.log(`    Database check: ${verifyResult.trim()}`);

    if (!verifyResult.includes('VERIFIED_AT_')) {
      throw new Error('Database user email_verified_at is still null!');
    }

    console.log('\n✅ ALL LIVE EMAIL VERIFICATION UI & BACKEND CHECKS PASSED!\n');
  } catch (err) {
    console.error('❌ TEST FAILED:', err);
    process.exitCode = 1;
  } finally {
    if (browser) await browser.close();
    await stopE2EServers();
  }
}

runTest();
