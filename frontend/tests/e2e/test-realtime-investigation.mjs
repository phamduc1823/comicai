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

export async function runRealtimeInvestigation(stagePrefix = 'pre_stash') {
  console.log(`\n======================================================`);
  console.log(`RUNNING REALTIME INVESTIGATION: [${stagePrefix}]`);
  console.log(`======================================================\n`);

  // Setup test users and data
  const setupCode = `
    use App\\Models\\User;
    use App\\Models\\Post;
    use App\\Models\\Comic;
    use App\\Models\\Chapter;
    use App\\Models\\Conversation;
    use App\\Models\\Message;
    use Illuminate\\Support\\Facades\\Hash;
    use Illuminate\\Support\\Facades\\DB;

    DB::table('cache')->truncate();

    // 1. User A (Sender)
    $userA = User::firstOrNew(['email' => 'realtime_user_a@comicai.test']);
    $userA->name = 'Realtime User A';
    $userA->password = Hash::make('Password123!');
    $userA->role = 'reader';
    $userA->is_active = true;
    $userA->email_verified_at = now();
    $userA->save();

    // 2. User B (Receiver)
    $userB = User::firstOrNew(['email' => 'realtime_user_b@comicai.test']);
    $userB->name = 'Realtime User B';
    $userB->password = Hash::make('Password123!');
    $userB->role = 'reader';
    $userB->is_active = true;
    $userB->email_verified_at = now();
    $userB->save();

    // Ensure conversation exists between A and B
    $u1 = min($userA->id, $userB->id);
    $u2 = max($userA->id, $userB->id);
    $conversation = Conversation::firstOrCreate(
        ['user_one_id' => $u1, 'user_two_id' => $u2],
        ['type' => 'direct']
    );
    // Sync participants
    $conversation->users()->syncWithoutDetaching([$userA->id, $userB->id]);

    // Ensure User B has a post for User A to like
    $postB = Post::firstOrCreate(
        ['user_id' => $userB->id, 'content' => 'Nội dung bài viết thử nghiệm tính năng notification realtime.']
    );

    // Get a comic and readable chapter for views test
    $comic = Comic::first();
    $chapter = $comic ? Chapter::where('comic_id', $comic->id)->orderBy('chapter_number', 'asc')->first() : null;

    echo json_encode([
        'user_a_id' => $userA->id,
        'user_b_id' => $userB->id,
        'conversation_id' => $conversation->id,
        'post_id' => $postB->id,
        'comic_slug' => $comic ? $comic->slug : null,
        'comic_id' => $comic ? $comic->id : null,
        'chapter_number' => $chapter ? $chapter->chapter_number : null,
        'chapter_id' => $chapter ? $chapter->id : null,
        'initial_comic_views' => $comic ? $comic->views_count : 0,
        'initial_chapter_views' => $chapter ? $chapter->views_count : 0,
    ]);
  `;

  const setupRaw = runPhp(setupCode);
  console.log('Setup raw output:', setupRaw);
  const jsonMatch = setupRaw.match(/\{"user_a_id":[\s\S]*\}/);
  if (!jsonMatch) {
    throw new Error('Failed to find JSON in setup output: ' + setupRaw);
  }
  const setupInfo = JSON.parse(jsonMatch[0]);
  console.log('Test fixtures ready:', setupInfo);

  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1440,900'],
  });

  const results = {
    stage: stagePrefix,
    chat_realtime: null,
    notification_realtime: null,
    views_realtime: null,
    console_errors: [],
  };

  try {
    // ----------------------------------------------------
    // TEST 1: CHAT REALTIME
    // ----------------------------------------------------
    console.log('\n--- [TEST 1] Chat Realtime Verification ---');
    const contextA = await browser.createBrowserContext();
    const pageA = await contextA.newPage();
    await pageA.setViewport({ width: 1440, height: 900 });

    const contextB = await browser.createBrowserContext();
    const pageB = await contextB.newPage();
    await pageB.setViewport({ width: 1440, height: 900 });

    const consoleLogsB = [];
    pageB.on('console', msg => {
      const text = msg.text();
      consoleLogsB.push(`[PAGE B ${msg.type()}]: ${text}`);
      if (msg.type() === 'error') {
        results.console_errors.push(text);
      }
    });

    // Login both users
    await loginUser(pageA, 'realtime_user_a@comicai.test');
    await loginUser(pageB, 'realtime_user_b@comicai.test');

    // Both navigate to the conversation in chat
    const chatUrl = `${BASE_URL}/chat?conversation_id=${setupInfo.conversation_id}`;
    console.log(`Page A navigating to ${chatUrl}`);
    await pageA.goto(chatUrl, { waitUntil: 'networkidle2' });
    console.log(`Page B navigating to ${chatUrl}`);
    await pageB.goto(chatUrl, { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 2000));

    // Send a message from User A
    const testMessageText = `Ping realtime message at ${Date.now()}`;
    console.log(`User A sending message: "${testMessageText}"`);

    // Type in chat input
    const inputSelector = 'textarea, input[placeholder*="tin nhắn"], input[placeholder*="Nhập"]';
    await pageA.waitForSelector(inputSelector, { timeout: 10000 });
    await pageA.type(inputSelector, testMessageText);

    const startTime = Date.now();
    // Press Enter or click send button
    await pageA.keyboard.press('Enter');

    // Wait to see if Page B receives the message WITHOUT refreshing
    console.log('Waiting for Page B to receive message realtime (max 5s)...');
    let receivedRealtime = false;
    let receiveDurationMs = null;

    try {
      await pageB.waitForFunction(
        (text) => document.body.innerText.includes(text),
        { timeout: 5000 },
        testMessageText
      );
      receiveDurationMs = Date.now() - startTime;
      receivedRealtime = true;
      console.log(`✅ Page B received message in ${receiveDurationMs}ms WITHOUT refresh!`);
    } catch (e) {
      console.log('❌ Page B did NOT receive message within 5s without refresh.');
      receivedRealtime = false;
    }

    await takeScreenshot(pageB, `${stagePrefix}_chat_page_b.png`);

    results.chat_realtime = {
      received_without_refresh: receivedRealtime,
      latency_ms: receiveDurationMs,
      details: receivedRealtime ? `Received in ${receiveDurationMs}ms` : 'Timed out (WebSocket offline or not received)',
    };

    // ----------------------------------------------------
    // TEST 2: NOTIFICATION REALTIME
    // ----------------------------------------------------
    console.log('\n--- [TEST 2] Notification Realtime Verification ---');
    // Page B goes to Home page with Notification Bell
    await pageB.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 1500));

    // Initial badge state for User B
    const initialBadge = await pageB.$('.notification-badge');
    const initialBadgeText = initialBadge ? await pageB.evaluate(el => el.textContent?.trim(), initialBadge) : '0';
    console.log('User B initial badge:', initialBadgeText);

    // Trigger notification: User A likes User B's post
    console.log(`User A likes User B's post (ID: ${setupInfo.post_id})...`);
    const likeStartTime = Date.now();
    const likeResult = runPhp(`
      use App\\Models\\User;
      use App\\Models\\Post;
      use App\\Services\\NotificationService;

      $userA = User::where('email', 'realtime_user_a@comicai.test')->first();
      $post = Post::find(${setupInfo.post_id});
      
      // Toggle like using community service logic or direct notification service
      app(NotificationService::class)->notifyPostLiked($post, $userA);
      echo "OK";
    `);
    console.log('Like event dispatch result:', likeResult);

    // Wait to see if User B's bell badge updates WITHOUT refresh
    console.log('Waiting for User B notification badge to update realtime (max 5s)...');
    let notifRealtime = false;
    let notifDurationMs = null;

    try {
      await pageB.waitForFunction(
        (prev) => {
          const badge = document.querySelector('.notification-badge');
          return badge && badge.textContent?.trim() !== prev;
        },
        { timeout: 5000 },
        initialBadgeText
      );
      notifDurationMs = Date.now() - likeStartTime;
      notifRealtime = true;
      console.log(`✅ User B notification badge updated in ${notifDurationMs}ms WITHOUT refresh!`);
    } catch (e) {
      console.log('❌ User B notification badge did NOT update within 5s without refresh.');
      notifRealtime = false;
    }

    // Now click the bell to check dropdown
    await pageB.click('.btn-notification-bell');
    await new Promise(r => setTimeout(r, 1000));
    await takeScreenshot(pageB, `${stagePrefix}_notification_page_b.png`);

    results.notification_realtime = {
      badge_updated_without_refresh: notifRealtime,
      latency_ms: notifDurationMs,
      details: notifRealtime ? `Updated in ${notifDurationMs}ms` : 'Did not update without refresh/open (polling/manual only)',
    };

    // ----------------------------------------------------
    // TEST 3: VIEWS REALTIME
    // ----------------------------------------------------
    console.log('\n--- [TEST 3] Views Realtime Verification ---');
    if (setupInfo.comic_slug && setupInfo.chapter_number) {
      // Check initial view count in DB
      const initialViewsDb = JSON.parse(runPhp(`
        use App\\Models\\Chapter;
        use App\\Models\\Comic;
        $c = Chapter::find(${setupInfo.chapter_id});
        $m = Comic::find(${setupInfo.comic_id});
        echo json_encode(['chapter_views' => $c->views_count, 'comic_views' => $m->views_count]);
      `));
      console.log('Initial views in DB:', initialViewsDb);

      // User A reads chapter on frontend
      const readerUrl = `${BASE_URL}/comics/${setupInfo.comic_slug}/chapters/${setupInfo.chapter_number}`;
      console.log(`User A navigating to reader: ${readerUrl}`);
      await pageA.goto(readerUrl, { waitUntil: 'networkidle2' });
      await new Promise(r => setTimeout(r, 2000));

      // Check DB again to verify view incremented
      const updatedViewsDb = JSON.parse(runPhp(`
        use App\\Models\\Chapter;
        use App\\Models\\Comic;
        $c = Chapter::find(${setupInfo.chapter_id});
        $m = Comic::find(${setupInfo.comic_id});
        echo json_encode(['chapter_views' => $c->views_count, 'comic_views' => $m->views_count]);
      `));
      console.log('Updated views in DB after read:', updatedViewsDb);

      const dbIncremented = updatedViewsDb.chapter_views > initialViewsDb.chapter_views || updatedViewsDb.comic_views > initialViewsDb.comic_views;

      // Check if Comic Detail page or Comics list on Page B updates views realtime without refresh
      const comicDetailUrl = `${BASE_URL}/comics/${setupInfo.comic_slug}`;
      await pageB.goto(comicDetailUrl, { waitUntil: 'networkidle2' });
      await new Promise(r => setTimeout(r, 1000));
      await takeScreenshot(pageB, `${stagePrefix}_views_comic_detail.png`);

      results.views_realtime = {
        db_increment_working: dbIncremented,
        initial_db: initialViewsDb,
        updated_db: updatedViewsDb,
        details: dbIncremented ? 'View count incremented in DB upon chapter reading' : 'Debounced by 30-min cache or not incremented',
      };
    } else {
      results.views_realtime = { details: 'No comics/chapters available to test' };
    }

    console.log('\n=== REALTIME INVESTIGATION RESULTS SUMMARY ===');
    console.log(JSON.stringify(results, null, 2));

    return results;

  } finally {
    await browser.close();
  }
}

// If executed directly:
if (process.argv[1] && process.argv[1].endsWith('test-realtime-investigation.mjs')) {
  const stage = process.argv[2] || 'pre_stash';
  runRealtimeInvestigation(stage).then(res => {
    fs.writeFileSync(path.resolve(SCRATCH_PATH, `results_${stage}.json`), JSON.stringify(res, null, 2));
    process.exit(0);
  }).catch(err => {
    console.error('Fatal test error:', err);
    process.exit(1);
  });
}
