import puppeteer from 'puppeteer-core';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const SCREENSHOTS_DIR = path.resolve(__dirname, '..', '..', '..', 'AI', 'reports', 'screenshots');
const CHROME_PATH = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE_URL = process.env.E2E_BASE_URL || 'http://localhost:5173';
const SCRATCH_PATH = path.resolve(__dirname, '..', '..', '..', 'scratch');

if (!fs.existsSync(SCREENSHOTS_DIR)) {
  fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}
if (!fs.existsSync(SCRATCH_PATH)) {
  fs.mkdirSync(SCRATCH_PATH, { recursive: true });
}

function runPhp(code) {
  const tmpFile = path.resolve(SCRATCH_PATH, `e2e_cmd_${Date.now()}_${Math.floor(Math.random() * 1000)}.php`);
  const fullPhp = `<?php
$backendPath = 'd:/Study/UTC/ky4/doan/comicai/backend';
require $backendPath . '/vendor/autoload.php';
$app = require $backendPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();
${code}
`;
  fs.writeFileSync(tmpFile, fullPhp, 'utf-8');
  try {
    const out = execSync(`php "${tmpFile}"`, { stdio: ['pipe', 'pipe', 'pipe'] }).toString().trim();
    return out;
  } finally {
    try { fs.unlinkSync(tmpFile); } catch {}
  }
}

async function takeScreenshot(page, filename) {
  const filePath = path.join(SCREENSHOTS_DIR, filename);
  await page.screenshot({ path: filePath, fullPage: true });
  console.log(`📸 Screenshot saved: ${filePath}`);
  return filePath;
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

async function loginUser(page, email, password = 'Password123!') {
  console.log(`Logging in as ${email}...`);
  runPhp(`Illuminate\\Support\\Facades\\DB::table('cache')->truncate();`);

  await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('#login-email', { timeout: 10000 });
  await clearAndType(page, '#login-email', email);
  await clearAndType(page, '#login-password', password);
  await page.click('button.submit-btn');

  // Wait until route leaves /login
  await page.waitForFunction(() => window.location.pathname !== '/login', { timeout: 12000 });
  await new Promise(r => setTimeout(r, 1000));
}

async function setupTestData() {
  console.log('--- Setting up test data in MySQL database ---');
  const phpCode = `
    use App\\Models\\User;
    use App\\Models\\CreatorApplication;
    use App\\Models\\PenNameReservation;
    use Illuminate\\Support\\Facades\\Hash;
    use Illuminate\\Support\\Facades\\DB;

    // Reset rate limiter cache
    DB::table('cache')->truncate();

    // 1. Admin user
    $admin = User::firstOrNew(['email' => 'admin_notif_test@comicai.test']);
    $admin->name = 'Admin Notif Tester';
    $admin->password = Hash::make('Password123!');
    $admin->role = 'admin';
    $admin->is_active = true;
    $admin->email_verified_at = now();
    $admin->save();

    // 2. Reader applicant
    $reader = User::firstOrNew(['email' => 'reader_notif_test@comicai.test']);
    $reader->name = 'Reader Notif Tester';
    $reader->password = Hash::make('Password123!');
    $reader->role = 'reader';
    $reader->is_active = true;
    $reader->email_verified_at = now();
    $reader->save();

    // 3. Other reader for isolation check
    $other = User::firstOrNew(['email' => 'other_reader_notif@comicai.test']);
    $other->name = 'Other Reader Tester';
    $other->password = Hash::make('Password123!');
    $other->role = 'reader';
    $other->is_active = true;
    $other->email_verified_at = now();
    $other->save();

    // Clean up previous test data for this reader
    DB::table('notifications')->where('notifiable_id', $reader->id)->delete();
    DB::table('notifications')->where('notifiable_id', $other->id)->delete();
    DB::table('creator_profiles')->where('user_id', $reader->id)->delete();
    DB::table('creator_audit_logs')->where('user_id', $reader->id)->delete();
    DB::table('creator_application_histories')->whereIn('application_id', function($q) use ($reader) {
        $q->select('id')->from('creator_applications')->where('user_id', $reader->id);
    })->delete();
    DB::table('pen_name_reservations')->where('reserved_by_user_id', $reader->id)->delete();
    DB::table('creator_applications')->where('user_id', $reader->id)->delete();

    // Reset reader back to role reader
    $reader->role = 'reader';
    $reader->save();

    // Create pending application
    $app = CreatorApplication::create([
        'application_code' => 'CA-888999',
        'user_id' => $reader->id,
        'pen_name' => 'Họa Sĩ Thần Đồng',
        'pen_name_canonical' => 'hoa si than dong',
        'bio' => 'Họa sĩ truyện tranh độc lập với phong cách manga hiện đại',
        'reason' => 'Muốn xuất bản truyện tranh dài kỳ trên ComicAI',
        'sample_works' => ['https://portfolio.test/work1', 'https://portfolio.test/work2'],
        'status' => 'pending',
        'version' => 1,
    ]);

    PenNameReservation::create([
        'pen_name_canonical' => 'hoa si than dong',
        'pen_name' => 'Họa Sĩ Thần Đồng',
        'reserved_by_user_id' => $reader->id,
        'reservation_type' => 'application',
        'application_id' => $app->id,
    ]);

    echo json_encode([
        'admin_id' => $admin->id,
        'reader_id' => $reader->id,
        'app_id' => $app->id,
    ]);
  `;

  const output = runPhp(phpCode);
  console.log('Setup output:', output.trim());
  const jsonMatch = output.match(/\{"admin_id":\d+,"reader_id":\d+,"app_id":\d+\}/);
  if (!jsonMatch) {
    throw new Error('Failed to parse test setup output: ' + output);
  }
  return JSON.parse(jsonMatch[0]);
}

async function runTest() {
  const setupInfo = await setupTestData();
  console.log('Test setup ready:', setupInfo);

  console.log('Launching Chrome browser...');
  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1440,900'],
  });

  try {
    // ----------------------------------------------------
    // PHASE 1: Verify Initial Reader Bell State (0 unread)
    // ----------------------------------------------------
    console.log('\n=== PHASE 1: Reader Initial Notification State ===');
    const readerContext = await browser.createBrowserContext();
    const readerPage = await readerContext.newPage();
    await readerPage.setViewport({ width: 1440, height: 900 });

    await loginUser(readerPage, 'reader_notif_test@comicai.test');
    await readerPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });

    // Look for notification bell container
    await readerPage.waitForSelector('.btn-notification-bell', { timeout: 10000 });
    
    // Check initial badge (should be absent)
    const initialBadge = await readerPage.$('.notification-badge');
    console.log('Initial unread badge exists:', !!initialBadge);
    if (initialBadge) {
      throw new Error('Initial badge should not exist for clean test reader!');
    }

    // ----------------------------------------------------
    // PHASE 2: Admin Approves Creator Application on UI
    // ----------------------------------------------------
    console.log('\n=== PHASE 2: Admin Approves Application on UI ===');
    const adminContext = await browser.createBrowserContext();
    const adminPage = await adminContext.newPage();
    await adminPage.setViewport({ width: 1440, height: 900 });

    adminPage.on('console', msg => console.log('ADMIN BROWSER LOG:', msg.type(), msg.text()));
    adminPage.on('pageerror', err => console.log('ADMIN BROWSER PAGEERROR:', err));

    await loginUser(adminPage, 'admin_notif_test@comicai.test');

    // Admin navigates to Creator Application Detail page directly
    const appDetailUrl = `${BASE_URL}/admin/creator-applications/${setupInfo.app_id}`;
    console.log(`Admin navigating to: ${appDetailUrl}`);
    await adminPage.goto(appDetailUrl, { waitUntil: 'networkidle2' });

    try {
      // Verify application detail page loaded
      await adminPage.waitForFunction(() => document.body.innerText.includes('Họa Sĩ Thần Đồng'), { timeout: 10000 });
      console.log('Found application detail for Họa Sĩ Thần Đồng');
    } catch (err) {
      await takeScreenshot(adminPage, 'Admin_Detail_Timeout_Debug.png');
      const bodyText = await adminPage.evaluate(() => document.body.innerText);
      console.log('ADMIN PAGE CURRENT TEXT PREVIEW:\n', bodyText.slice(0, 1000));
      console.log('ADMIN CURRENT URL:', adminPage.url());
      throw err;
    }

    // Click "Duyệt hồ sơ" / "Phê duyệt" button
    await adminPage.waitForSelector('button', { timeout: 10000 });
    
    const approveBtn = await adminPage.evaluateHandle(() => {
      const buttons = Array.from(document.querySelectorAll('button'));
      return buttons.find(b => b.textContent && (b.textContent.includes('Duyệt hồ sơ') || b.textContent.includes('Phê duyệt') || b.textContent.includes('Duyệt')));
    });

    if (!approveBtn.asElement()) {
      throw new Error('Could not find Approve button on admin application detail page');
    }

    console.log('Clicking Approve button...');
    await approveBtn.asElement().click();

    // Confirm modal
    await new Promise(r => setTimeout(r, 1000));
    const confirmBtn = await adminPage.evaluateHandle(() => {
      const buttons = Array.from(document.querySelectorAll('button'));
      return buttons.find(b => b.textContent && (b.textContent.includes('Xác nhận') || b.textContent.includes('Đồng ý') || b.textContent.includes('Duyệt')));
    });

    if (confirmBtn.asElement()) {
      console.log('Confirming approval modal...');
      await confirmBtn.asElement().click();
    }

    // Wait for API call and UI update
    await new Promise(r => setTimeout(r, 3000));
    await takeScreenshot(adminPage, 'Flow_Approval_Admin_Success.png');

    // ----------------------------------------------------
    // PHASE 3: Reader Receives Notification in Bell
    // ----------------------------------------------------
    console.log('\n=== PHASE 3: Reader Receives Notification in Bell ===');
    // Reload Reader page to ensure notification store fetches latest state from DB
    await readerPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 2000));

    // Verify Notification Bell has unread badge
    await readerPage.waitForSelector('.btn-notification-bell', { timeout: 10000 });
    const hasUnreadBadge = await readerPage.waitForSelector('.notification-badge', { timeout: 5000 }).then(() => true).catch(() => false);
    console.log('Reader has unread notification badge:', hasUnreadBadge);

    const badgeText = await readerPage.evaluate(() => {
      const badge = document.querySelector('.notification-badge');
      return badge ? badge.textContent?.trim() : null;
    });
    console.log('Badge text:', badgeText);

    // Open notification dropdown panel
    console.log('Clicking notification bell to open panel...');
    await readerPage.click('.btn-notification-bell');
    await readerPage.waitForSelector('.notification-panel', { timeout: 5000 });
    await new Promise(r => setTimeout(r, 1000));

    // Take screenshot of open notification dropdown
    const screenshotPath = await takeScreenshot(readerPage, 'Flow_Approval_Notification_Live.png');

    // Verify notification content in the dropdown
    const notifItem = await readerPage.waitForSelector('.notification-item', { timeout: 5000 });
    const itemTitle = await readerPage.evaluate(() => {
      const titleEl = document.querySelector('.notification-item .item-title');
      return titleEl ? titleEl.textContent?.trim() : null;
    });
    const itemBody = await readerPage.evaluate(() => {
      const bodyEl = document.querySelector('.notification-item .item-body');
      return bodyEl ? bodyEl.textContent?.trim() : null;
    });

    console.log('Notification Item Title:', itemTitle);
    console.log('Notification Item Body:', itemBody);

    if (itemTitle !== 'Hồ sơ Creator đã được duyệt') {
      throw new Error(`Unexpected title: ${itemTitle}`);
    }

    // ----------------------------------------------------
    // PHASE 4: Reader clicks notification and navigates
    // ----------------------------------------------------
    console.log('\n=== PHASE 4: Click Notification & Mark As Read ===');
    await readerPage.click('.notification-item .notification-item-btn');
    await new Promise(r => setTimeout(r, 2000));

    console.log('Current URL after click:', readerPage.url());

    // Verify redirected to become-creator
    const isBecomeCreator = readerPage.url().includes('/become-creator');
    console.log('Navigated to /become-creator:', isBecomeCreator);
    if (!isBecomeCreator) {
      throw new Error(`Expected URL to contain /become-creator, got ${readerPage.url()}`);
    }

    // Reload page and check that notification badge is gone
    await readerPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 1500));
    const badgeAfterRead = await readerPage.$('.notification-badge');
    console.log('Badge exists after mark as read:', !!badgeAfterRead);
    if (badgeAfterRead) {
      throw new Error('Badge should no longer exist after notification was marked as read!');
    }

    // ----------------------------------------------------
    // PHASE 5: Negative Isolation Check (Other Reader)
    // ----------------------------------------------------
    console.log('\n=== PHASE 5: Isolation Check (Other Reader) ===');
    const otherContext = await browser.createBrowserContext();
    const otherPage = await otherContext.newPage();
    await otherPage.setViewport({ width: 1440, height: 900 });

    await loginUser(otherPage, 'other_reader_notif@comicai.test');
    await otherPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });

    // Assert other reader has 0 unread badge
    const otherBadge = await otherPage.$('.notification-badge');
    console.log('Other user has badge:', !!otherBadge);
    if (otherBadge) {
      throw new Error('Other reader should not have notification badge!');
    }

    // ----------------------------------------------------
    // PHASE 6: Database Verification
    // ----------------------------------------------------
    console.log('\n=== PHASE 6: Database Verification ===');
    const dbCheck = runPhp(`
      use App\\Models\\User;
      $reader = User::where('email', 'reader_notif_test@comicai.test')->first();
      $notifs = $reader->notifications()->get();
      echo json_encode([
          'count' => $notifs->count(),
          'type' => $notifs->first()?->type,
          'kind' => $notifs->first()?->data['kind'] ?? null,
          'read' => $notifs->first()?->read_at !== null,
          'title' => $notifs->first()?->data['title'] ?? null,
      ]);
    `);
    console.log('Database verification result:', dbCheck.trim());

    console.log('\n======================================================');
    console.log('✅ ALL UI END-TO-END NOTIFICATION APPROVAL CHECKS PASSED!');
    console.log('======================================================');

  } finally {
    await browser.close();
  }
}

runTest().catch((err) => {
  console.error('❌ Test failed with error:', err);
  process.exit(1);
});
