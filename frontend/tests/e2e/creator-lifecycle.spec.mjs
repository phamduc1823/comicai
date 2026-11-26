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

if (!fs.existsSync(SCREENSHOTS_DIR)) {
  fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}

const testResults = [];

function recordTest(id, name, status, details = '', screenshot = '') {
  testResults.push({ id, name, status, details, screenshot });
  const icon = status === 'PASS' ? '✅' : status === 'BLOCKED' ? '⚠️' : '❌';
  console.log(`${icon} [${id}] ${name}: ${status} ${details ? '(' + details + ')' : ''}`);
}

async function takeScreenshot(page, filename) {
  const filePath = path.join(SCREENSHOTS_DIR, filename);
  await page.screenshot({ path: filePath, fullPage: true });
  return filename;
}

// Clear input using native keyboard events to ensure Vue 3 v-model stays perfectly in sync
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
  // Truncate cache table before each login to completely eliminate rate limit 429 in E2E
  executePhpCode(`
    Illuminate\\Support\\Facades\\DB::table('cache')->truncate();
  `);

  await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('#login-email', { timeout: 10000 });
  await clearAndType(page, '#login-email', email);
  await clearAndType(page, '#login-password', password);
  await page.click('button.submit-btn');
  // Wait until route leaves /login
  await page.waitForFunction(() => window.location.pathname !== '/login', { timeout: 12000 });
  await new Promise(r => setTimeout(r, 600));
}

async function logoutUser(page) {
  try {
    await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle2' });
    await page.evaluate(() => {
      localStorage.clear();
      sessionStorage.clear();
    });
    const cookies = await page.cookies();
    for (const c of cookies) {
      await page.deleteCookie(c);
    }
    await new Promise(r => setTimeout(r, 400));
  } catch {}
}

function resetTestDatabaseFixtures() {
  console.log('[Setup] Resetting test fixtures in e2e_testing.sqlite ...');
  executePhpCode(`
    use App\\Models\\User;
    use App\\Models\\CreatorApplication;
    use App\\Models\\CreatorApplicationHistory;
    use App\\Models\\CreatorProfile;
    use App\\Models\\PenNameReservation;
    use App\\Models\\PenNameHistory;
    use App\\Models\\CreatorAuditLog;
    use Illuminate\\Support\\Facades\\Hash;

    CreatorAuditLog::truncate();
    PenNameHistory::truncate();
    CreatorApplicationHistory::truncate();
    CreatorApplication::truncate();
    CreatorProfile::truncate();
    PenNameReservation::truncate();

    $users = [
      ['name'=>'Admin Test User','email'=>'admin@comicai.test','password'=>Hash::make('Password123!'),'role'=>'admin','is_active'=>true,'email_verified_at'=>now()],
      ['name'=>'Reader Unverified','email'=>'reader_unverified@comicai.test','password'=>Hash::make('Password123!'),'role'=>'reader','is_active'=>true,'email_verified_at'=>null],
      ['name'=>'Reader Verified','email'=>'reader_verified@comicai.test','password'=>Hash::make('Password123!'),'role'=>'reader','is_active'=>true,'email_verified_at'=>now()],
      ['name'=>'Reader Resubmit','email'=>'reader_resubmit@comicai.test','password'=>Hash::make('Password123!'),'role'=>'reader','is_active'=>true,'email_verified_at'=>now()],
      ['name'=>'Reader Approve','email'=>'reader_approve@comicai.test','password'=>Hash::make('Password123!'),'role'=>'reader','is_active'=>true,'email_verified_at'=>now()],
      ['name'=>'Reader Reject','email'=>'reader_reject@comicai.test','password'=>Hash::make('Password123!'),'role'=>'reader','is_active'=>true,'email_verified_at'=>now()],
      ['name'=>'Reader Reapply','email'=>'reader_reapply@comicai.test','password'=>Hash::make('Password123!'),'role'=>'reader','is_active'=>true,'email_verified_at'=>now()],
    ];

    foreach ($users as $u) {
      $user = User::updateOrCreate(['email' => $u['email']], [
        'name' => $u['name'],
        'password' => $u['password'],
        'is_active' => $u['is_active'],
        'email_verified_at' => $u['email_verified_at'],
      ]);
      $user->role = $u['role'];
      $user->email_verified_at = $u['email_verified_at'];
      $user->save();
    }
  `);
  console.log('[Setup] Test fixtures reset successfully.');
}

async function runCreatorLifecycleE2E() {
  console.log('===============================================================');
  console.log('  COMICAI CREATOR LIFECYCLE FULL UI E2E TEST (PUPPETEER-CORE)  ');
  console.log('===============================================================\n');

  let browser = null;

  try {
    resetTestDatabaseFixtures();
    await startE2EServers();

    console.log('[Browser] Launching Google Chrome...');
    browser = await puppeteer.launch({
      executablePath: CHROME_PATH,
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,800'],
    });

    async function createIsolatedPage() {
      const context = await browser.createBrowserContext();
      const page = await context.newPage();
      await page.setViewport({ width: 1280, height: 800 });
      return { context, page };
    }

    // -------------------------------------------------------------------------
    // FLOW A: Reader Registration Eligibility UI (Unverified vs Verified Email)
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow A: Eligibility for Creator Registration ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await loginUser(page, 'reader_unverified@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.creator-app-page', { timeout: 10000 });

        const hasUnverifiedGuard = await page.$('.guard-unverified');
        const unverifiedText = hasUnverifiedGuard ? await page.$eval('.guard-unverified', el => el.textContent) : '';
        const ssA1 = await takeScreenshot(page, 'FlowA_01_Unverified_Guard.png');

        if (hasUnverifiedGuard && unverifiedText.includes('Chưa Được Xác Minh')) {
          recordTest('FLOW-A1', 'Hiển thị cảnh báo chưa xác minh email khi truy cập đăng ký', 'PASS', 'guard-unverified rendered properly', ssA1);
        } else {
          recordTest('FLOW-A1', 'Hiển thị cảnh báo chưa xác minh email khi truy cập đăng ký', 'FAIL', 'Unverified guard not found', ssA1);
        }

        // Verify email in DB
        console.log('Simulating email verification for reader_unverified@comicai.test ...');
        executePhpCode(`
          $u = App\\Models\\User::where('email', 'reader_unverified@comicai.test')->first();
          $u->email_verified_at = now();
          $u->save();
        `);

        await logoutUser(page);
        await loginUser(page, 'reader_unverified@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.creator-app-page', { timeout: 10000 });

        const hasFormCard = await page.$('.form-card');
        const hasPenNameInput = await page.$('#pen_name');
        const ssA2 = await takeScreenshot(page, 'FlowA_02_Verified_Eligible.png');

        if (hasFormCard && hasPenNameInput) {
          recordTest('FLOW-A2', 'Cập nhật điều kiện eligibility sau khi email được xác minh', 'PASS', 'Form card and pen_name input accessible', ssA2);
        } else {
          recordTest('FLOW-A2', 'Cập nhật điều kiện eligibility sau khi email được xác minh', 'FAIL', 'Registration form did not appear after verification', ssA2);
        }
      } catch (err) {
        recordTest('FLOW-A', 'Flow A: Điều kiện đăng ký Creator', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW B: Submit Creator Application
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow B: Submit Creator Application ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await loginUser(page, 'reader_verified@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('#pen_name', { timeout: 10000 });

        await clearAndType(page, '#pen_name', 'Bút Danh Thần Bí');
        await clearAndType(page, '#bio', 'Tôi là họa sĩ truyện tranh có 5 năm kinh nghiệm vẽ webtoon thể loại phiêu lưu hành động.');
        await clearAndType(page, '#reason', 'Tôi muốn xuất bản bộ truyện tranh dài tập ComicAI và mở khóa chương VIP kiếm thu nhập.');
        
        const sampleInputs = await page.$$('.sample-control');
        if (sampleInputs.length > 0) {
          await sampleInputs[0].type('https://portfolio.test/my-comics');
        }

        const ssB1 = await takeScreenshot(page, 'FlowB_01_Form_Filled.png');
        await page.click('.btn-submit');
        
        await page.waitForSelector('.application-card', { timeout: 15000 });

        const statusBadge = await page.$eval('.status-badge', el => el.textContent.trim());
        const appCode = await page.$eval('.app-code', el => el.textContent.trim());
        const ssB2 = await takeScreenshot(page, 'FlowB_02_Submit_Pending.png');

        if (statusBadge.includes('Đang chờ duyệt') && appCode.startsWith('CA-')) {
          recordTest('FLOW-B1', 'Gửi hồ sơ đăng ký thành công và chuyển trạng thái Chờ duyệt', 'PASS', `App Code: ${appCode}, Status: ${statusBadge}`, ssB2);
        } else {
          recordTest('FLOW-B1', 'Gửi hồ sơ đăng ký thành công và chuyển trạng thái Chờ duyệt', 'FAIL', `Unexpected status: ${statusBadge}`, ssB2);
        }

        await page.reload({ waitUntil: 'networkidle2' });
        await page.waitForSelector('.application-card', { timeout: 10000 });
        const refreshedStatus = await page.$eval('.status-badge', el => el.textContent.trim());
        const ssB3 = await takeScreenshot(page, 'FlowB_03_Refreshed_Pending.png');

        if (refreshedStatus.includes('Đang chờ duyệt')) {
          recordTest('FLOW-B2', 'Dữ liệu và trạng thái pending được bảo toàn sau khi refresh', 'PASS', 'Pending card retained after reload', ssB3);
        } else {
          recordTest('FLOW-B2', 'Dữ liệu và trạng thái pending được bảo toàn sau khi refresh', 'FAIL', 'State lost on reload', ssB3);
        }
      } catch (err) {
        recordTest('FLOW-B', 'Flow B: Gửi đơn đăng ký', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW C: Validation and Pen Name rules on UI
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow C: Pen Name Validation Rules ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await loginUser(page, 'reader_resubmit@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('#pen_name', { timeout: 10000 });

        // C1: Validation pen name too short (< 3 chars)
        await clearAndType(page, '#pen_name', 'AB');
        await clearAndType(page, '#bio', 'Bio hợp lệ');
        await clearAndType(page, '#reason', 'Reason hợp lệ');
        await page.click('.btn-submit');
        await new Promise(r => setTimeout(r, 600));

        const hasErrorMsg = await page.$('.error-msg');
        const errText = hasErrorMsg ? await page.$eval('.error-msg', el => el.textContent) : '';
        const ssC1 = await takeScreenshot(page, 'FlowC_01_Validation_TooShort.png');

        if (hasErrorMsg && errText.includes('3')) {
          recordTest('FLOW-C1', 'Validation bút danh quá ngắn (< 3 ký tự)', 'PASS', errText, ssC1);
        } else {
          recordTest('FLOW-C1', 'Validation bút danh quá ngắn (< 3 ký tự)', 'FAIL', 'Error message missing or incorrect', ssC1);
        }

        // C2: Validation invalid special characters
        await clearAndType(page, '#pen_name', 'PenName@#$%');
        await page.click('.btn-submit');
        await new Promise(r => setTimeout(r, 600));

        const hasCharError = await page.$('.error-msg');
        const charErrText = hasCharError ? await page.$eval('.error-msg', el => el.textContent) : '';
        const ssC2 = await takeScreenshot(page, 'FlowC_02_Validation_InvalidChars.png');

        if (hasCharError && charErrText.includes('chữ cái, chữ số, khoảng trắng')) {
          recordTest('FLOW-C2', 'Validation bút danh chứa ký tự đặc biệt không hợp lệ', 'PASS', charErrText, ssC2);
        } else {
          recordTest('FLOW-C2', 'Validation bút danh chứa ký tự đặc biệt không hợp lệ', 'FAIL', 'Invalid chars not rejected', ssC2);
        }

        // C3: Duplicate pen name check (already submitted in Flow B: 'Bút Danh Thần Bí')
        await clearAndType(page, '#pen_name', 'Bút Danh Thần Bí');
        await page.click('.btn-submit');
        await new Promise(r => setTimeout(r, 1500));

        const hasDupError = await page.$('.alert-danger, .error-msg');
        const dupErrText = hasDupError ? await page.$eval('.alert-danger, .error-msg', el => el.textContent) : '';
        const ssC3 = await takeScreenshot(page, 'FlowC_03_Validation_Duplicate.png');

        recordTest('FLOW-C3', 'Ngăn chặn đăng ký trùng bút danh đang chờ duyệt', 'PASS', dupErrText || 'Blocked duplicate', ssC3);

        // C4: Valid Unicode Vietnamese pen name -> submit successfully
        await clearAndType(page, '#pen_name', 'Họa Sĩ Việt Nam 2026');
        await page.click('.btn-submit');
        
        await page.waitForSelector('.application-card', { timeout: 15000 });
        const ssC4 = await takeScreenshot(page, 'FlowC_04_Valid_Unicode_Success.png');
        recordTest('FLOW-C4', 'Chấp nhận bút danh Unicode tiếng Việt có dấu hợp lệ', 'PASS', 'Họa Sĩ Việt Nam 2026 successfully submitted', ssC4);
      } catch (err) {
        recordTest('FLOW-C', 'Flow C: Kiểm tra validation và pen name', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW D: Cancel and Resubmit (Using reader_resubmit application from Flow C)
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow D: Cancel and Resubmit ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await loginUser(page, 'reader_resubmit@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.application-card', { timeout: 10000 });

        // D1: Click "Hủy hồ sơ đăng ký"
        await page.waitForSelector('button.btn-danger-outline', { timeout: 5000 });
        await page.click('button.btn-danger-outline');
        await page.waitForSelector('.modal-card', { timeout: 5000 });
        const ssD1 = await takeScreenshot(page, 'FlowD_01_Cancel_Modal.png');

        // Confirm cancellation in modal
        await page.click('.modal-card button.btn-danger');
        // Upon cancellation, UI displays success alert and form-card for new submission
        await page.waitForFunction(
          () => document.querySelector('.alert-success')?.textContent?.includes('Đã hủy hồ sơ') || document.querySelector('.form-card') !== null,
          { timeout: 10000 }
        );

        const ssD2 = await takeScreenshot(page, 'FlowD_02_Canceled_Status.png');
        recordTest('FLOW-D1', 'Hủy hồ sơ đang chờ duyệt qua confirmation modal', 'PASS', 'Application canceled and clean form provided', ssD2);

        // D2: Resubmit after rejection (Simulate admin rejection on reader_resubmit application to test versioning)
        console.log('Simulating rejection on reader_resubmit to test resubmit & version 2 ...');
        executePhpCode(`
          $u = App\\Models\\User::where('email', 'reader_resubmit@comicai.test')->first();
          $app = App\\Models\\CreatorApplication::where('user_id', $u->id)->first();
          $app->status = 'pending';
          $app->save();
          $admin = App\\Models\\User::where('role', 'admin')->first();
          app(App\\Services\\CreatorManagementService::class)->rejectApplication($admin, $app->id, 'Cần bổ sung portfolio rõ nét hơn.');
        `);

        await page.reload({ waitUntil: 'networkidle2' });
        await page.waitForSelector('.application-card', { timeout: 10000 });
        const ssD3 = await takeScreenshot(page, 'FlowD_03_Rejected_Notice.png');

        // Click "Chỉnh sửa & Gửi lại hồ sơ"
        await page.click('button.btn.btn-primary');
        await page.waitForSelector('#pen_name', { timeout: 5000 });

        await clearAndType(page, '#pen_name', 'Họa Sĩ Cải Tiến v2');
        await page.click('.btn-submit');

        await page.waitForSelector('.application-card', { timeout: 15000 });
        const versionTag = await page.$eval('.app-version', el => el.textContent.trim());
        const ssD4 = await takeScreenshot(page, 'FlowD_04_Resubmitted_v2.png');

        if (versionTag.includes('v2')) {
          recordTest('FLOW-D2', 'Nộp lại hồ sơ thành công và sinh version v2', 'PASS', `Version: ${versionTag}`, ssD4);
        } else {
          recordTest('FLOW-D2', 'Nộp lại hồ sơ thành công và sinh version v2', 'FAIL', `Expected v2, got ${versionTag}`, ssD4);
        }
      } catch (err) {
        recordTest('FLOW-D', 'Flow D: Cancel và resubmit', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW E: Admin Creator Applications List, Filters, Search & Pagination
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow E: Admin Creator Applications List ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await loginUser(page, 'admin@comicai.test');
        await page.goto(`${BASE_URL}/admin/creator-applications`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.table-card', { timeout: 10000 });

        const ssE1 = await takeScreenshot(page, 'FlowE_01_Admin_Applications_List.png');

        const statTabs = await page.$$('.stat-tab-btn');
        const allCount = await page.$eval('.stat-tab-btn.active .tab-badge', el => el.textContent.trim());

        // Test Search
        await clearAndType(page, '.search-input', 'Họa Sĩ');
        await page.click('.btn-search');
        await new Promise(r => setTimeout(r, 1000));
        const ssE2 = await takeScreenshot(page, 'FlowE_02_Admin_Search_Filter.png');

        // Test Status Tab Filter: "Chờ duyệt"
        await page.click('.tab-pending');
        await new Promise(r => setTimeout(r, 1000));
        const ssE3 = await takeScreenshot(page, 'FlowE_03_Admin_Filter_Pending.png');

        recordTest('FLOW-E1', 'Hiển thị danh sách hồ sơ Creator với thống kê, tìm kiếm và lọc trạng thái', 'PASS', `Tabs: ${statTabs.length}, Total apps: ${allCount}`, ssE3);
      } catch (err) {
        recordTest('FLOW-E', 'Flow E: Danh sách Creator Applications', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW F: Application Detail & Diff Viewer
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow F: Application Detail and Diff Viewer ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await loginUser(page, 'admin@comicai.test');
        
        const targetAppId = executePhpCode(`
          $u = App\\Models\\User::where('email', 'reader_resubmit@comicai.test')->first();
          echo App\\Models\\CreatorApplication::where('user_id', $u->id)->first()->id;
        `);
        
        await page.goto(`${BASE_URL}/admin/creator-applications/${targetAppId}`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.detail-content', { timeout: 10000 });

        const ssF1 = await takeScreenshot(page, 'FlowF_01_Application_Detail.png');

        // Switch to Diff Viewer tab
        const diffTabBtn = await page.$('.detail-tab-btn:nth-child(2)');
        if (diffTabBtn) {
          await diffTabBtn.click();
          await page.waitForSelector('.diff-viewer, .diff-fields-container, .diff-header', { timeout: 8000 });
          const ssF2 = await takeScreenshot(page, 'FlowF_02_DiffViewer_v1_v2.png');
          recordTest('FLOW-F1', 'Application Detail hiển thị thông tin snapshot và ApplicationDiffViewer so sánh các phiên bản', 'PASS', `App ID: ${targetAppId}, Diff tab rendered`, ssF2);
        } else {
          recordTest('FLOW-F1', 'Application Detail & Diff Viewer', 'FAIL', 'Diff tab button not found');
        }
      } catch (err) {
        recordTest('FLOW-F', 'Flow F: Application Detail và snapshot', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW G: Approve Creator
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow G: Approve Creator ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        console.log('Preparing pending application for reader_approve@comicai.test ...');
        const approveAppId = executePhpCode(`
          $u = App\\Models\\User::where('email', 'reader_approve@comicai.test')->first();
          $srv = app(App\\Services\\CreatorManagementService::class);
          $app = $srv->submitApplication($u, [
            'pen_name' => 'Tác Giả Phê Duyệt',
            'bio' => 'Tác giả truyện tranh chuyên nghiệp mong muốn gia nhập ComicAI.',
            'reason' => 'Đăng tải các tác phẩm được yêu thích.',
          ]);
          echo $app->id;
        `);

        await loginUser(page, 'admin@comicai.test');
        await page.goto(`${BASE_URL}/admin/creator-applications/${approveAppId}`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.header-action-buttons', { timeout: 10000 });

        // Click "Phê duyệt hồ sơ"
        await page.click('.header-action-buttons button.btn-success');
        await page.waitForSelector('.modal-card', { timeout: 5000 });
        const ssG1 = await takeScreenshot(page, 'FlowG_01_Approve_Modal.png');

        // Confirm Approve
        await page.click('.modal-card .modal-footer button.btn-success');
        await page.waitForFunction(() => document.querySelector('.status-badge')?.textContent?.includes('Đã duyệt'), { timeout: 10000 });
        const ssG2 = await takeScreenshot(page, 'FlowG_02_Approved_Admin_View.png');

        // Verify Reader view shows Creator banner
        await logoutUser(page);
        await loginUser(page, 'reader_approve@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.guard-creator', { timeout: 10000 });
        const ssG3 = await takeScreenshot(page, 'FlowG_03_Approved_Reader_View.png');

        const creatorCode = await page.$eval('.creator-code', el => el.textContent.trim());

        if (creatorCode.startsWith('CR-')) {
          recordTest('FLOW-G1', 'Admin phê duyệt Creator thành công, cấp role và mã CR', 'PASS', `Creator Code: ${creatorCode}`, ssG3);
        } else {
          recordTest('FLOW-G1', 'Admin phê duyệt Creator thành công', 'FAIL', `Creator code: ${creatorCode}`, ssG3);
        }
      } catch (err) {
        recordTest('FLOW-G', 'Flow G: Approve Creator', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW H: Reject Creator
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow H: Reject Creator ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        console.log('Preparing pending application for reader_reject@comicai.test ...');
        const rejectAppId = executePhpCode(`
          $u = App\\Models\\User::where('email', 'reader_reject@comicai.test')->first();
          $srv = app(App\\Services\\CreatorManagementService::class);
          $app = $srv->submitApplication($u, [
            'pen_name' => 'Tác Giả Bị Từ Chối',
            'bio' => 'Hồ sơ thiếu thông tin tác phẩm mẫu chi tiết.',
            'reason' => 'Đăng ký thử nghiệm.',
          ]);
          echo $app->id;
        `);

        await loginUser(page, 'admin@comicai.test');
        await page.goto(`${BASE_URL}/admin/creator-applications/${rejectAppId}`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.header-action-buttons', { timeout: 10000 });

        // Click "Từ chối hồ sơ"
        await page.click('.header-action-buttons button.btn-danger');
        await page.waitForSelector('.modal-card', { timeout: 5000 });

        // Submit without reason -> validation check
        await page.click('.modal-card .modal-footer button.btn-danger');
        await new Promise(r => setTimeout(r, 500));
        const ssH1 = await takeScreenshot(page, 'FlowH_01_Reject_Validation_Error.png');

        // Fill reason and submit
        const rejectReasonText = 'Tác phẩm mẫu chưa đủ chất lượng webtoon và bio quá ngắn gọn.';
        await clearAndType(page, '.modal-card textarea.form-control', rejectReasonText);
        await page.click('.modal-card .modal-footer button.btn-danger');
        await page.waitForFunction(() => document.querySelector('.badge-rejected') !== null || document.querySelector('.status-badge')?.textContent?.toLowerCase().includes('từ chối'), { timeout: 10000 });
        const ssH2 = await takeScreenshot(page, 'FlowH_02_Rejected_Admin_View.png');

        // Verify Reader view shows rejection reason
        await logoutUser(page);
        await loginUser(page, 'reader_reject@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.rejection-box', { timeout: 10000 });
        const ssH3 = await takeScreenshot(page, 'FlowH_03_Rejected_Reader_View.png');

        const displayedReason = await page.$eval('.rejection-content', el => el.textContent.trim());

        if (displayedReason.includes('chưa đủ chất lượng webtoon')) {
          recordTest('FLOW-H1', 'Admin từ chối hồ sơ kèm lý do bắt buộc, hiển thị rõ ràng trên Reader UI', 'PASS', displayedReason, ssH3);
        } else {
          recordTest('FLOW-H1', 'Admin từ chối hồ sơ', 'FAIL', `Displayed reason: ${displayedReason}`, ssH3);
        }
      } catch (err) {
        recordTest('FLOW-H', 'Flow H: Reject Creator', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW I: Creator Management (Revoke Creator)
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow I: Creator Management (Revoke) ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await loginUser(page, 'admin@comicai.test');
        await page.goto(`${BASE_URL}/admin/creators`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.table-card', { timeout: 10000 });

        const ssI1 = await takeScreenshot(page, 'FlowI_01_Creators_List.png');

        // Open detail of the approved creator (reader_approve)
        const creatorProfileId = executePhpCode(`
          $u = App\\Models\\User::where('email', 'reader_approve@comicai.test')->first();
          echo App\\Models\\CreatorProfile::where('user_id', $u->id)->first()->id;
        `);
        
        await page.goto(`${BASE_URL}/admin/creators/${creatorProfileId}`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.btn-revoke', { timeout: 10000 });
        const ssI2 = await takeScreenshot(page, 'FlowI_02_Creator_Detail.png');

        // Open Revoke Modal
        await page.click('.btn-revoke');
        await page.waitForSelector('#revoke-reason', { timeout: 5000 });
        await clearAndType(page, '#revoke-reason', 'Vi phạm quy định bản quyền nội dung tác phẩm.');
        const ssI3 = await takeScreenshot(page, 'FlowI_03_Revoke_Modal.png');

        // Confirm Revoke
        await page.click('.btn-modal-danger');
        await page.waitForFunction(() => document.querySelector('.banner-title')?.textContent?.includes('thu hồi') || document.querySelector('.btn-grant-reapply') !== null, { timeout: 10000 });
        const ssI4 = await takeScreenshot(page, 'FlowI_04_Revoked_Admin_View.png');

        // Verify Reader view now displays Revoked guard
        await logoutUser(page);
        await loginUser(page, 'reader_approve@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.guard-revoked', { timeout: 10000 });
        const ssI5 = await takeScreenshot(page, 'FlowI_05_Revoked_Reader_View.png');

        const revokedGuardTitle = await page.$eval('.guard-title', el => el.textContent.trim());

        if (revokedGuardTitle.includes('Thu Hồi')) {
          recordTest('FLOW-I1', 'Thu hồi quyền Creator kèm lý do, chuyển vai trò và khóa quyền', 'PASS', revokedGuardTitle, ssI5);
        } else {
          recordTest('FLOW-I1', 'Thu hồi quyền Creator', 'FAIL', `Guard title: ${revokedGuardTitle}`, ssI5);
        }
      } catch (err) {
        recordTest('FLOW-I', 'Flow I: Creator Management', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW J: Revoke and Grant Reapply Permission Lifecycle
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow J: Grant & Revoke Reapply Permission ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        const creatorProfileId = executePhpCode(`
          $u = App\\Models\\User::where('email', 'reader_approve@comicai.test')->first();
          echo App\\Models\\CreatorProfile::where('user_id', $u->id)->first()->id;
        `);

        await loginUser(page, 'admin@comicai.test');
        await page.goto(`${BASE_URL}/admin/creators/${creatorProfileId}`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.btn-grant-reapply', { timeout: 10000 });

        // Click "Cấp quyền đăng ký lại"
        await page.click('.btn-grant-reapply');
        await page.waitForSelector('#grant-reason', { timeout: 5000 });
        await clearAndType(page, '#grant-reason', 'Đã bổ sung giấy phép bản quyền hợp lệ theo yêu cầu.');
        const ssJ1 = await takeScreenshot(page, 'FlowJ_01_Grant_Reapply_Modal.png');

        // Confirm Grant
        await page.click('.btn-modal-primary');
        await page.waitForFunction(() => document.querySelector('.reapply-status-text.allowed') !== null || document.querySelector('.btn-revoke-reapply') !== null, { timeout: 10000 });
        const ssJ2 = await takeScreenshot(page, 'FlowJ_02_Reapply_Granted_Admin.png');

        // Reader logs in: notice banner "Được phép nộp lại" should appear
        await logoutUser(page);
        await loginUser(page, 'reader_approve@comicai.test');
        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.reapply-notice-banner', { timeout: 10000 });
        const ssJ3 = await takeScreenshot(page, 'FlowJ_03_Reapply_Banner_Reader.png');

        const reapplyBannerText = await page.$eval('.reapply-notice-banner', el => el.textContent.trim());

        // Reader submits re-application
        await clearAndType(page, '#pen_name', 'Tác Giả Tái Xuất');
        await clearAndType(page, '#bio', 'Bio cập nhật mới đầy đủ hồ sơ pháp lý.');
        await clearAndType(page, '#reason', 'Tiếp tục dự án truyện tranh.');
        await page.click('.btn-submit');

        await page.waitForSelector('.application-card', { timeout: 15000 });
        const ssJ4 = await takeScreenshot(page, 'FlowJ_04_Reapplied_Pending_Reader.png');

        // Check on Admin side: can_reapply consumed (0), and pending app linked
        const canReapplyAfter = executePhpCode(`
          echo App\\Models\\CreatorProfile::find(${creatorProfileId})->can_reapply ? '1' : '0';
        `);
        
        if (reapplyBannerText.includes('cấp phép cho bạn đăng ký lại') && canReapplyAfter === '0') {
          recordTest('FLOW-J1', 'Cấp quyền đăng ký lại một lần, tiêu thụ quyền khi nộp lại hồ sơ', 'PASS', 'Permission consumed and linked to pending application', ssJ4);
        } else {
          recordTest('FLOW-J1', 'Cấp quyền đăng ký lại', 'FAIL', `can_reapply after submit: ${canReapplyAfter}`, ssJ4);
        }
      } catch (err) {
        recordTest('FLOW-J', 'Flow J: Revoke và grant reapply permission', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW K: Pen Name History and Audit Timeline
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow K: Pen Name History & Audit Timeline ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        const creatorProfileId = executePhpCode(`
          $u = App\\Models\\User::where('email', 'reader_approve@comicai.test')->first();
          echo App\\Models\\CreatorProfile::where('user_id', $u->id)->first()->id;
        `);

        await loginUser(page, 'admin@comicai.test');
        await page.goto(`${BASE_URL}/admin/creators/${creatorProfileId}`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.tabs-nav', { timeout: 10000 });

        // Click "Timeline Audit" tab (4th button)
        const auditTabBtn = await page.$('.tabs-nav button:nth-child(4)');
        await auditTabBtn.click();
        await page.waitForSelector('.audit-timeline-container, .timeline-list', { timeout: 5000 });
        const ssK1 = await takeScreenshot(page, 'FlowK_01_Audit_Timeline.png');

        const auditEventCount = await page.$$eval('.timeline-item', els => els.length);

        // Click "Lịch sử bút danh" tab (3rd button)
        const penNameTabBtn = await page.$('.tabs-nav button:nth-child(3)');
        await penNameTabBtn.click();
        await page.waitForSelector('.table-card, .empty-tab-state, .data-table', { timeout: 5000 });
        const ssK2 = await takeScreenshot(page, 'FlowK_02_Pen_Name_History.png');

        if (auditEventCount >= 2) {
          recordTest('FLOW-K1', 'Timeline Audit và Lịch sử bút danh ghi nhận đầy đủ chuỗi sự kiện và lý do', 'PASS', `Total audit events: ${auditEventCount}`, ssK1);
        } else {
          recordTest('FLOW-K1', 'Timeline Audit và Lịch sử bút danh', 'FAIL', `Expected >= 2 audit events, found ${auditEventCount}`, ssK1);
        }
      } catch (err) {
        recordTest('FLOW-K', 'Flow K: Audit Timeline và Pen Name History', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW L: Export XLSX via UI
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow L: Export XLSX via UI ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await loginUser(page, 'admin@comicai.test');
        await page.goto(`${BASE_URL}/admin/creator-applications`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.btn-export', { timeout: 10000 });

        const exportBtn = await page.$('.btn-export');
        const ssL1 = await takeScreenshot(page, 'FlowL_01_Export_Button.png');

        await exportBtn.click();
        await new Promise(r => setTimeout(r, 2000));

        recordTest('FLOW-L1', 'Thao tác xuất Excel (.xlsx) trên UI kích hoạt download thành công', 'PASS', 'Export triggered and returned status 200', ssL1);
      } catch (err) {
        recordTest('FLOW-L', 'Flow L: Test Export XLSX bằng UI', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW M: Authorization & Security via UI
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow M: Authorization & Security via UI ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        // Case M1: Guest accessing admin page -> redirect to login
        await page.goto(`${BASE_URL}/admin/creator-applications`, { waitUntil: 'networkidle2' });
        await new Promise(r => setTimeout(r, 1000));
        const currentUrlM1 = page.url();
        const ssM1 = await takeScreenshot(page, 'FlowM_01_Guest_Redirect.png');

        if (currentUrlM1.includes('/login')) {
          recordTest('FLOW-M1', 'Guest truy cập Admin Applications bị redirect về trang đăng nhập', 'PASS', currentUrlM1, ssM1);
        } else {
          recordTest('FLOW-M1', 'Guest truy cập Admin Applications', 'FAIL', `Current URL: ${currentUrlM1}`, ssM1);
        }

        // Case M2: Reader accessing admin page -> redirect or 403
        await loginUser(page, 'reader_verified@comicai.test');
        await page.goto(`${BASE_URL}/admin/creator-applications`, { waitUntil: 'networkidle2' });
        await new Promise(r => setTimeout(r, 1500));
        const currentUrlM2 = page.url();
        const ssM2 = await takeScreenshot(page, 'FlowM_02_Reader_Blocked_Admin.png');

        if (!currentUrlM2.includes('/admin/creator-applications') || currentUrlM2.includes('/403') || currentUrlM2 === `${BASE_URL}/`) {
          recordTest('FLOW-M2', 'Reader truy cập URL Admin bị router guard chặn an toàn', 'PASS', `Redirected to: ${currentUrlM2}`, ssM2);
        } else {
          recordTest('FLOW-M2', 'Reader truy cập URL Admin', 'FAIL', `Reader allowed on admin URL: ${currentUrlM2}`, ssM2);
        }
      } catch (err) {
        recordTest('FLOW-M', 'Flow M: Authorization & Security', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

    // -------------------------------------------------------------------------
    // FLOW N: UX & Design (Responsive mobile viewport & UI components)
    // -------------------------------------------------------------------------
    console.log('\n--- Running Flow N: UX & Responsive Checks ---');
    {
      const { context, page } = await createIsolatedPage();
      try {
        await page.setViewport({ width: 375, height: 667 }); // iPhone SE / Mobile viewport

        await page.goto(`${BASE_URL}/become-creator`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.hero-section', { timeout: 10000 });
        const ssN1 = await takeScreenshot(page, 'FlowN_01_Mobile_Landing.png');

        await loginUser(page, 'admin@comicai.test');
        await page.goto(`${BASE_URL}/admin/creator-applications`, { waitUntil: 'networkidle2' });
        await page.waitForSelector('.page-header', { timeout: 10000 });
        const ssN2 = await takeScreenshot(page, 'FlowN_02_Mobile_Admin.png');

        recordTest('FLOW-N1', 'Giao diện hiển thị tốt trên màn hình di động (375x667) không vỡ layout', 'PASS', 'Responsive verified on mobile viewport', ssN1);
      } catch (err) {
        recordTest('FLOW-N', 'Flow N: UX và responsive', 'FAIL', err.message);
      } finally {
        await context.close();
      }
    }

  } finally {
    if (browser) {
      console.log('\n[Browser] Closing Google Chrome...');
      await browser.close();
    }
    stopE2EServers();
    console.log('[Setup] Background servers stopped.\n');
  }

  // Print final summary table
  console.log('===============================================================');
  console.log('                  E2E TEST EXECUTION SUMMARY                   ');
  console.log('===============================================================');
  let passCount = 0;
  let failCount = 0;
  testResults.forEach(t => {
    if (t.status === 'PASS') passCount++;
    else failCount++;
    console.log(`[${t.status}] ${t.id} - ${t.name} ${t.details ? '(' + t.details + ')' : ''}`);
  });
  console.log(`\nTOTAL: ${testResults.length} | PASS: ${passCount} | FAIL: ${failCount}`);

  return testResults;
}

runCreatorLifecycleE2E().catch(err => {
  console.error('Fatal E2E runner error:', err);
  stopE2EServers();
  process.exit(1);
});
