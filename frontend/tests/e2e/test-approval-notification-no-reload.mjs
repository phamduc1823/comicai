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
  console.log('--- Setting up fresh test data in MySQL database ---');
  const phpCode = `
    use App\\Models\\User;
    use App\\Models\\CreatorApplication;
    use App\\Models\\PenNameReservation;
    use Illuminate\\Support\\Facades\\Hash;
    use Illuminate\\Support\\Facades\\DB;

    DB::table('cache')->truncate();

    // 1. Admin user
    $admin = User::firstOrNew(['email' => 'admin_notif_live@comicai.test']);
    $admin->name = 'Admin Notif Live';
    $admin->password = Hash::make('Password123!');
    $admin->role = 'admin';
    $admin->is_active = true;
    $admin->email_verified_at = now();
    $admin->save();

    // 2. Reader applicant
    $reader = User::firstOrNew(['email' => 'reader_notif_live@comicai.test']);
    $reader->name = 'Reader Notif Live';
    $reader->password = Hash::make('Password123!');
    $reader->role = 'reader';
    $reader->is_active = true;
    $reader->email_verified_at = now();
    $reader->save();

    // 3. Other reader for isolation check
    $other = User::firstOrNew(['email' => 'other_notif_live@comicai.test']);
    $other->name = 'Other Reader Live';
    $other->password = Hash::make('Password123!');
    $other->role = 'reader';
    $other->is_active = true;
    $other->email_verified_at = now();
    $other->save();

    // Clean up previous test data
    DB::table('notifications')->where('notifiable_id', $reader->id)->delete();
    DB::table('notifications')->where('notifiable_id', $other->id)->delete();
    DB::table('creator_profiles')->where('user_id', $reader->id)->delete();
    DB::table('creator_audit_logs')->where('user_id', $reader->id)->delete();
    DB::table('creator_application_histories')->whereIn('application_id', function($q) use ($reader) {
        $q->select('id')->from('creator_applications')->where('user_id', $reader->id);
    })->delete();
    DB::table('pen_name_reservations')->where('reserved_by_user_id', $reader->id)->delete();
    DB::table('creator_applications')->where('user_id', $reader->id)->delete();

    // Create pending application
    $app = CreatorApplication::create([
        'application_code' => 'CA-999111',
        'user_id' => $reader->id,
        'pen_name' => 'Họa Sĩ Không Reload',
        'pen_name_canonical' => 'hoa si khong reload',
        'bio' => 'Họa sĩ truyện tranh độc lập kiểm thử tính năng auto-update notification',
        'reason' => 'Muốn xuất bản truyện tranh trên ComicAI',
        'sample_works' => ['https://portfolio.test/work1'],
        'status' => 'pending',
        'version' => 1,
    ]);

    PenNameReservation::create([
        'pen_name_canonical' => 'hoa si khong reload',
        'pen_name' => 'Họa Sĩ Không Reload',
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

async function runLiveTest() {
  const setupInfo = await setupTestData();
  console.log('Test setup ready:', setupInfo);

  console.log('Launching Chrome browser with 2 independent contexts...');
  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1440,900'],
  });

  const testLog = {
    approve_time: null,
    appear_time: null,
    duration_ms: null,
    mechanism: null,
    console_errors_reader: [],
    network_requests_reader: [],
  };

  try {
    // ====================================================
    // CONTEXT 1: READER (STAYS OPEN WITHOUT PAGE RELOAD)
    // ====================================================
    console.log('\n=== [CONTEXT 1] Reader logs in and keeps page open ===');
    const readerContext = await browser.createBrowserContext();
    const readerPage = await readerContext.newPage();
    await readerPage.setViewport({ width: 1440, height: 900 });

    readerPage.on('console', msg => {
      const text = msg.text();
      if (msg.type() === 'error') {
        testLog.console_errors_reader.push(text);
      }
    });

    readerPage.on('request', req => {
      const url = req.url();
      if (url.includes('/api/notifications')) {
        testLog.network_requests_reader.push({
          time: new Date().toISOString(),
          method: req.method(),
          url: url.replace(BASE_URL, ''),
        });
      }
    });

    await loginUser(readerPage, 'reader_notif_live@comicai.test');
    await readerPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });

    // Ensure notification bell is visible
    await readerPage.waitForSelector('.btn-notification-bell', { timeout: 10000 });
    
    // Assert initial unread badge does NOT exist
    const initialBadge = await readerPage.$('.notification-badge');
    console.log('Initial unread badge exists:', !!initialBadge);
    if (initialBadge) {
      throw new Error('Initial badge should NOT exist for fresh reader!');
    }
    console.log('Reader is idle on home page. Ready for Admin approval. DO NOT RELOAD PAGE.');

    // ====================================================
    // CONTEXT 2: ADMIN (APPROVES APPLICATION)
    // ====================================================
    console.log('\n=== [CONTEXT 2] Admin logs in and approves application ===');
    const adminContext = await browser.createBrowserContext();
    const adminPage = await adminContext.newPage();
    await adminPage.setViewport({ width: 1440, height: 900 });

    await loginUser(adminPage, 'admin_notif_live@comicai.test');

    const appDetailUrl = `${BASE_URL}/admin/creator-applications/${setupInfo.app_id}`;
    console.log(`Admin navigating to: ${appDetailUrl}`);
    await adminPage.goto(appDetailUrl, { waitUntil: 'networkidle2' });

    await adminPage.waitForFunction(() => document.body.innerText.includes('Họa Sĩ Không Reload'), { timeout: 10000 });
    console.log('Found application for Họa Sĩ Không Reload');

    // Find Approve button
    const approveBtn = await adminPage.evaluateHandle(() => {
      const buttons = Array.from(document.querySelectorAll('button'));
      return buttons.find(b => b.textContent && (b.textContent.includes('Duyệt hồ sơ') || b.textContent.includes('Phê duyệt') || b.textContent.includes('Duyệt')));
    });

    if (!approveBtn.asElement()) {
      throw new Error('Could not find Approve button on admin page');
    }

    console.log('Admin clicking Approve button...');
    await approveBtn.asElement().click();

    await new Promise(r => setTimeout(r, 800));
    const confirmBtn = await adminPage.evaluateHandle(() => {
      const buttons = Array.from(document.querySelectorAll('button'));
      return buttons.find(b => b.textContent && (b.textContent.includes('Xác nhận') || b.textContent.includes('Đồng ý') || b.textContent.includes('Duyệt')));
    });

    if (confirmBtn.asElement()) {
      console.log('Admin clicking confirm button...');
      await confirmBtn.asElement().click();
    }

    // Mark the exact approve timestamp
    testLog.approve_time = Date.now();
    console.log(`⏱️ Admin approved at timestamp: ${new Date(testLog.approve_time).toISOString()}`);

    // Wait for Admin approval request to finish
    await new Promise(r => setTimeout(r, 1500));

    // ====================================================
    // VERIFY READER: BADGE MUST APPEAR WITHOUT RELOAD
    // ====================================================
    console.log('\n=== [VERIFICATION] Waiting for Reader badge to update AUTOMATICALLY (NO RELOAD) ===');
    console.log('Watching Reader page (max wait 15s)...');

    // Wait for .notification-badge to appear on readerPage WITHOUT RELOADING
    await readerPage.waitForSelector('.notification-badge', { timeout: 16000 });
    testLog.appear_time = Date.now();
    testLog.duration_ms = testLog.appear_time - testLog.approve_time;

    console.log(`🎯 Notification badge APPEARED on Reader UI in ${testLog.duration_ms}ms (${(testLog.duration_ms / 1000).toFixed(2)}s) WITHOUT RELOAD!`);

    const badgeText = await readerPage.evaluate(() => {
      const el = document.querySelector('.notification-badge');
      return el ? el.textContent?.trim() : null;
    });
    console.log(`Reader badge number: "${badgeText}"`);
    if (badgeText !== '1') {
      throw new Error(`Expected badge to be 1, got ${badgeText}`);
    }

    // Take screenshot showing unread badge appeared automatically without reload
    await takeScreenshot(readerPage, 'Flow_Live_Badge_Auto_Updated_No_Reload.png');

    // ====================================================
    // VERIFY DROPDOWN: NOTIFICATION APPEARS IN DROPDOWN
    // ====================================================
    console.log('\n=== Opening Notification Dropdown to verify contents ===');
    await readerPage.click('.btn-notification-bell');
    await readerPage.waitForSelector('.notification-panel', { timeout: 5000 });
    await new Promise(r => setTimeout(r, 800));

    // Take screenshot of dropdown showing the notification card
    await takeScreenshot(readerPage, 'Flow_Live_Dropdown_Auto_Appeared_No_Reload.png');

    // Verify item content
    await readerPage.waitForSelector('.notification-item', { timeout: 5000 });
    const itemInfo = await readerPage.evaluate(() => {
      const items = Array.from(document.querySelectorAll('.notification-item'));
      const first = items[0];
      return {
        count: items.length,
        title: first?.querySelector('.item-title')?.textContent?.trim(),
        body: first?.querySelector('.item-body')?.textContent?.trim(),
        isUnread: first?.classList.contains('is-unread'),
      };
    });

    console.log('Notification Item Details in Dropdown:', itemInfo);
    if (itemInfo.count !== 1) {
      throw new Error(`Expected exactly 1 notification, found ${itemInfo.count} (check deduplication)`);
    }
    if (itemInfo.title !== 'Hồ sơ Creator đã được duyệt') {
      throw new Error(`Expected title 'Hồ sơ Creator đã được duyệt', got '${itemInfo.title}'`);
    }

    // ====================================================
    // MARK AS READ AND NAVIGATION
    // ====================================================
    console.log('\n=== Testing Mark As Read & Navigation ===');
    await readerPage.click('.notification-item .notification-item-btn');
    await new Promise(r => setTimeout(r, 2000));

    console.log('Reader navigated to URL:', readerPage.url());
    if (!readerPage.url().includes('/become-creator')) {
      throw new Error(`Expected URL to include /become-creator, got ${readerPage.url()}`);
    }

    // Reload page to verify persistence
    await readerPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });
    const badgeAfterRead = await readerPage.$('.notification-badge');
    console.log('Badge exists after mark as read and reload:', !!badgeAfterRead);
    if (badgeAfterRead) {
      throw new Error('Badge should no longer exist after notification is read');
    }

    // ====================================================
    // USER ISOLATION CHECK
    // ====================================================
    console.log('\n=== Testing User Isolation ===');
    const otherContext = await browser.createBrowserContext();
    const otherPage = await otherContext.newPage();
    await otherPage.setViewport({ width: 1440, height: 900 });

    await loginUser(otherPage, 'other_notif_live@comicai.test');
    await otherPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });
    const otherBadge = await otherPage.$('.notification-badge');
    console.log('Other user has badge:', !!otherBadge);
    if (otherBadge) {
      throw new Error('Other reader should NOT have received this notification!');
    }

    // Determine mechanism
    if (testLog.duration_ms < 3000) {
      testLog.mechanism = 'REALTIME VERIFIED';
    } else {
      testLog.mechanism = 'POLLING VERIFIED';
    }

    console.log('\n======================================================');
    console.log(`✅ TEST SUCCEEDED: ${testLog.mechanism}`);
    console.log(`Time to appear: ${testLog.duration_ms}ms (${(testLog.duration_ms / 1000).toFixed(2)}s)`);
    console.log('======================================================\n');

    fs.writeFileSync(
      path.resolve(SCRATCH_PATH, 'notification_auto_update_result.json'),
      JSON.stringify(testLog, null, 2)
    );

    return testLog;

  } finally {
    await browser.close();
  }
}

runLiveTest().catch(err => {
  console.error('❌ Test failed with error:', err);
  process.exit(1);
});
