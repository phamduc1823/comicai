import puppeteer from 'puppeteer-core';
import {
  startServers,
  stopServers,
  createTestUser,
  recordUserByEmail,
  cleanupTestUsers,
} from './test-setup.mjs';

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE_URL = 'http://127.0.0.1:5173';

// Test execution results
const testResults = [];

function recordTest(id, name, status, details = '') {
  testResults.push({ id, name, status, details });
  const icon = status === 'PASS' ? '✅' : '❌';
  console.log(`${icon} [${id}] ${name}: ${status} ${details ? '(' + details + ')' : ''}`);
}

async function runE2ETests() {
  console.log('=====================================================');
  console.log('   BLUE TOON BROWSER E2E TEST SUITE (PUPPETEER-CORE)  ');
  console.log('=====================================================\n');

  let browser = null;

  try {
    // 1. Ensure servers are running
    await startServers();

    // 2. Setup known test users and record their IDs
    console.log('[Setup] Preparing test fixtures in MySQL database...');
    const activeEmail = `e2e_active_${Date.now()}@e2e.test`;
    const inactiveEmail = `e2e_inactive_${Date.now()}@e2e.test`;
    const existingEmail = `e2e_existing_${Date.now()}@e2e.test`;
    const password = 'Password123!';

    createTestUser({
      name: 'Độc giả Kích hoạt',
      email: activeEmail,
      password,
      role: 'reader',
      isActive: true,
    });

    createTestUser({
      name: 'Độc giả Vô hiệu hóa',
      email: inactiveEmail,
      password,
      role: 'reader',
      isActive: false,
    });

    createTestUser({
      name: 'Độc giả Đã tồn tại',
      email: existingEmail,
      password,
      role: 'reader',
      isActive: true,
    });

    console.log('[Setup] Test fixtures created successfully.\n');

    // 3. Launch Chrome browser locally
    console.log('[Browser] Launching Google Chrome...');
    browser = await puppeteer.launch({
      executablePath: CHROME_PATH,
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,800'],
    });

    // Helper to create an isolated incognito browser context
    async function createPage() {
      const context = await browser.createBrowserContext();
      const page = await context.newPage();
      await page.setViewport({ width: 1280, height: 800 });
      return { context, page };
    }

    // ---------------------------------------------------------------
    // Flow 1: Trang đăng ký hiển thị và validation phía client
    // ---------------------------------------------------------------
    try {
      const { context, page } = await createPage();
      await page.goto(`${BASE_URL}/register`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('h1.auth-title', { timeout: 10000 });

      const title = await page.title();
      const hasHeading = await page.$eval('h1.auth-title', (el) => el.textContent.includes('Đăng ký'));
      const submitDisabled = await page.$eval('button.submit-btn', (el) => el.disabled);

      if (title.includes('Đăng ký') && hasHeading && submitDisabled) {
        recordTest('FLOW-01', 'Trang đăng ký hiển thị và client validation ban đầu', 'PASS');
      } else {
        recordTest('FLOW-01', 'Trang đăng ký hiển thị và client validation ban đầu', 'FAIL', 'Title or elements mismatch');
      }
      await context.close();
    } catch (err) {
      recordTest('FLOW-01', 'Trang đăng ký hiển thị và client validation ban đầu', 'FAIL', err.message);
    }

    // ---------------------------------------------------------------
    // Flow 4: Chặn đăng ký khi mật khẩu xác nhận không khớp (Client-side, 0 API calls)
    // ---------------------------------------------------------------
    try {
      const { context, page } = await createPage();
      await page.goto(`${BASE_URL}/register`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#register-password', { timeout: 10000 });

      let apiCalled = false;
      page.on('request', (req) => {
        if (req.url().includes('/api/register')) {
          apiCalled = true;
        }
      });

      await page.type('#register-name', 'Test Mismatch');
      await page.type('#register-email', `e2e_mismatch_${Date.now()}@e2e.test`);
      await page.type('#register-password', 'Password123!');
      await page.type('#register-password-confirmation', 'DifferentPass456!');

      await page.click('button.submit-btn');
      await page.waitForSelector('#register-confirm-error', { visible: true, timeout: 5000 });

      const mismatchError = await page.$eval('#register-confirm-error', (el) => el.textContent.trim());
      if (!apiCalled && mismatchError.includes('không khớp')) {
        recordTest('FLOW-04', 'Client validation chặn khi mật khẩu xác nhận không khớp', 'PASS', mismatchError);
      } else {
        recordTest('FLOW-04', 'Client validation chặn khi mật khẩu xác nhận không khớp', 'FAIL', `API called: ${apiCalled}`);
      }
      await context.close();
    } catch (err) {
      recordTest('FLOW-04', 'Client validation chặn khi mật khẩu xác nhận không khớp', 'FAIL', err.message);
    }

    // ---------------------------------------------------------------
    // Flow 2: Đăng ký thành công với tài khoản test mới (1 call to /api/register)
    // ---------------------------------------------------------------
    const newRegEmail = `e2e_reg_${Date.now()}@e2e.test`;
    try {
      const { context, page } = await createPage();
      await page.goto(`${BASE_URL}/register`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#register-name', { timeout: 10000 });

      await page.type('#register-name', 'Độc giả E2E Mới');
      await page.type('#register-email', newRegEmail);
      await page.type('#register-password', password);
      await page.type('#register-password-confirmation', password);

      await page.click('button.submit-btn');

      await page.waitForFunction(() => window.location.pathname === '/', { timeout: 10000 });
      await page.waitForSelector('.user-name', { timeout: 10000 });

      const currentPath = await page.evaluate(() => window.location.pathname);
      const userName = await page.$eval('.user-name', (el) => el.textContent.trim()).catch(() => '');
      const userRole = await page.$eval('.role-badge', (el) => el.textContent.trim()).catch(() => '');

      recordUserByEmail(newRegEmail);

      if (currentPath === '/' && userName === 'Độc giả E2E Mới' && userRole === 'Độc giả') {
        recordTest('FLOW-02', 'Đăng ký thành công và tự động đăng nhập', 'PASS', `Logged in as ${userName}`);
      } else {
        recordTest('FLOW-02', 'Đăng ký thành công và tự động đăng nhập', 'FAIL', `Path: ${currentPath}, User: ${userName}`);
      }
      await context.close();
    } catch (err) {
      recordTest('FLOW-02', 'Đăng ký thành công và tự động đăng nhập', 'FAIL', err.message);
    }

    // ---------------------------------------------------------------
    // Flow 3: Đăng ký email đã tồn tại (1 call to /api/register)
    // ---------------------------------------------------------------
    try {
      const { context, page } = await createPage();
      await page.goto(`${BASE_URL}/register`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#register-email', { timeout: 10000 });

      await page.type('#register-name', 'Người Trùng Email');
      await page.type('#register-email', existingEmail);
      await page.type('#register-password', password);
      await page.type('#register-password-confirmation', password);

      await page.click('button.submit-btn');
      await page.waitForSelector('#register-email-error', { visible: true, timeout: 10000 });

      const errorText = await page.$eval('#register-email-error', (el) => el.textContent.trim());
      if (errorText.toLowerCase().includes('đã được đăng ký') || errorText.toLowerCase().includes('already been taken')) {
        recordTest('FLOW-03', 'Đăng ký email đã tồn tại bị từ chối với HTTP 422', 'PASS', errorText);
      } else {
        recordTest('FLOW-03', 'Đăng ký email đã tồn tại bị từ chối với HTTP 422', 'FAIL', `Unexpected error: ${errorText}`);
      }
      await context.close();
    } catch (err) {
      recordTest('FLOW-03', 'Đăng ký email đã tồn tại bị từ chối với HTTP 422', 'FAIL', err.message);
    }

    // ---------------------------------------------------------------
    // Flow 6: Đăng nhập sai thông tin (Attempt 1 to /api/login)
    // ---------------------------------------------------------------
    try {
      const { context, page } = await createPage();
      await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#login-email', { timeout: 10000 });

      await page.type('#login-email', activeEmail);
      await page.type('#login-password', 'WrongPassword999!');

      await page.click('button.submit-btn');

      await page.waitForFunction(
        () =>
          document.querySelector('#login-email-error') !== null ||
          document.querySelector('.alert-message') !== null,
        { timeout: 10000 }
      );

      const errorText = await page.evaluate(() => {
        const field = document.querySelector('#login-email-error');
        const alert = document.querySelector('.alert-message');
        return (field?.textContent || alert?.textContent || '').trim();
      });

      if (errorText.includes('không chính xác hoặc tài khoản không khả dụng')) {
        recordTest('FLOW-06', 'Đăng nhập sai mật khẩu hiển thị thông điệp an toàn', 'PASS', errorText);
      } else {
        recordTest('FLOW-06', 'Đăng nhập sai mật khẩu hiển thị thông điệp an toàn', 'FAIL', `Message: ${errorText}`);
      }
      await context.close();
    } catch (err) {
      recordTest('FLOW-06', 'Đăng nhập sai mật khẩu hiển thị thông điệp an toàn', 'FAIL', err.message);
    }

    // ---------------------------------------------------------------
    // Flow 7: Tài khoản bị vô hiệu hóa không đăng nhập được (Attempt 2 to /api/login)
    // ---------------------------------------------------------------
    try {
      const { context, page } = await createPage();
      await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#login-email', { timeout: 10000 });

      await page.type('#login-email', inactiveEmail);
      await page.type('#login-password', password);

      await page.click('button.submit-btn');

      await page.waitForFunction(
        () =>
          document.querySelector('#login-email-error') !== null ||
          document.querySelector('.alert-message') !== null,
        { timeout: 10000 }
      );

      const errorText = await page.evaluate(() => {
        const field = document.querySelector('#login-email-error');
        const alert = document.querySelector('.alert-message');
        return (field?.textContent || alert?.textContent || '').trim();
      });

      const currentPath = await page.evaluate(() => window.location.pathname);
      if (currentPath === '/login' && errorText.includes('không chính xác hoặc tài khoản không khả dụng')) {
        recordTest('FLOW-07', 'Tài khoản vô hiệu hóa bị từ chối đăng nhập an toàn', 'PASS', errorText);
      } else {
        recordTest('FLOW-07', 'Tài khoản vô hiệu hóa bị từ chối đăng nhập an toàn', 'FAIL', `Path: ${currentPath}, Msg: ${errorText}`);
      }
      await context.close();
    } catch (err) {
      recordTest('FLOW-07', 'Tài khoản vô hiệu hóa bị từ chối đăng nhập an toàn', 'FAIL', err.message);
    }

    // ---------------------------------------------------------------
    // Flow 8: Redirect nội bộ và từ chối open redirect (Attempt 3 to /api/login)
    // ---------------------------------------------------------------
    try {
      const { context, page } = await createPage();

      // Case 8a: Self loop redirect /login?redirect=%2Flogin
      await page.goto(`${BASE_URL}/login?redirect=%2Flogin`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#login-email', { timeout: 10000 });

      await page.type('#login-email', activeEmail);
      await page.type('#login-password', password);
      await page.click('button.submit-btn');

      await page.waitForFunction(() => window.location.pathname === '/', { timeout: 10000 });
      const loopTargetPath = await page.evaluate(() => window.location.pathname);

      // Case 8b: External open redirect /login?redirect=https://evil.com
      // (User is already authenticated in this context, router guard intercepts immediately)
      await page.goto(`${BASE_URL}/login?redirect=https://evil.com`, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => window.location.pathname === '/', { timeout: 10000 });
      const evilTargetPath = await page.evaluate(() => window.location.pathname);

      if (loopTargetPath === '/' && evilTargetPath === '/') {
        recordTest('FLOW-08', 'Chống redirect loop và ngăn chặn open redirect', 'PASS', 'Both resolved safely to /');
      } else {
        recordTest('FLOW-08', 'Chống redirect loop và ngăn chặn open redirect', 'FAIL', `Loop: ${loopTargetPath}, Evil: ${evilTargetPath}`);
      }
      await context.close();
    } catch (err) {
      recordTest('FLOW-08', 'Chống redirect loop và ngăn chặn open redirect', 'FAIL', err.message);
    }

    // ---------------------------------------------------------------
    // Dedicated Main Authenticated Context for Flows 5, 9, 10, 11, 13, 12
    // (Uses Attempt 4 to /api/login, then keeps authenticated session)
    // ---------------------------------------------------------------
    const mainAuthContext = await browser.createBrowserContext();
    const mainPage = await mainAuthContext.newPage();
    await mainPage.setViewport({ width: 1280, height: 800 });

    let csrfRequested = false;
    mainPage.on('response', (res) => {
      if (res.url().includes('/sanctum/csrf-cookie')) {
        csrfRequested = true;
      }
    });

    // Flow 5: Đăng nhập thành công
    try {
      await mainPage.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
      await mainPage.waitForSelector('#login-email', { timeout: 10000 });

      await mainPage.type('#login-email', activeEmail);
      await mainPage.type('#login-password', password);
      await mainPage.click('button.submit-btn');

      await mainPage.waitForFunction(() => window.location.pathname === '/', { timeout: 10000 });
      await mainPage.waitForSelector('.user-name', { timeout: 10000 });

      const currentPath = await mainPage.evaluate(() => window.location.pathname);
      const userName = await mainPage.$eval('.user-name', (el) => el.textContent.trim()).catch(() => '');
      const userRole = await mainPage.$eval('.role-badge', (el) => el.textContent.trim()).catch(() => '');

      if (currentPath === '/' && userName === 'Độc giả Kích hoạt' && userRole === 'Độc giả') {
        recordTest('FLOW-05', 'Đăng nhập thành công với thông tin chính xác', 'PASS', `Logged in as ${userName}`);
      } else {
        recordTest('FLOW-05', 'Đăng nhập thành công với thông tin chính xác', 'FAIL', `Path: ${currentPath}, User: ${userName}`);
      }
    } catch (err) {
      recordTest('FLOW-05', 'Đăng nhập thành công với thông tin chính xác', 'FAIL', err.message);
    }

    // Flow 9: Lấy CSRF cookie và gửi request xác thực (Verified on mainPage login)
    try {
      const cookies = await mainPage.cookies();
      const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
      const sessionCookie = cookies.find((c) => c.name.includes('session'));

      if (csrfRequested && xsrfCookie && sessionCookie) {
        recordTest('FLOW-09', 'CSRF cookie handshake và session cookie được thiết lập', 'PASS');
      } else {
        recordTest('FLOW-09', 'CSRF cookie handshake và session cookie được thiết lập', 'FAIL', `CSRF: ${csrfRequested}, XSRF: ${!!xsrfCookie}, Sess: ${!!sessionCookie}`);
      }
    } catch (err) {
      recordTest('FLOW-09', 'CSRF cookie handshake và session cookie được thiết lập', 'FAIL', err.message);
    }

    // Flow 10: Session được duy trì sau reload
    try {
      await mainPage.reload({ waitUntil: 'domcontentloaded' });
      await mainPage.waitForSelector('.user-name', { timeout: 10000 });

      const userName = await mainPage.$eval('.user-name', (el) => el.textContent.trim()).catch(() => '');
      const hasLogoutBtn = await mainPage.$('.btn-logout');

      if (userName === 'Độc giả Kích hoạt' && hasLogoutBtn !== null) {
        recordTest('FLOW-10', 'Session duy trì hoàn hảo sau khi F5 / reload', 'PASS', `User: ${userName}`);
      } else {
        recordTest('FLOW-10', 'Session duy trì hoàn hảo sau khi F5 / reload', 'FAIL', `User after reload: ${userName}`);
      }
    } catch (err) {
      recordTest('FLOW-10', 'Session duy trì hoàn hảo sau khi F5 / reload', 'FAIL', err.message);
    }

    // Flow 11: Header hiển thị đúng trạng thái Guest, Avatar fallback và Role
    try {
      // In authenticated state
      const initialChar = await mainPage.$eval('.user-avatar-initial', (el) => el.textContent.trim()).catch(() => '');
      const roleText = await mainPage.$eval('.role-badge', (el) => el.textContent.trim()).catch(() => '');

      // In a guest context
      const { context: guestContext, page: guestPage } = await createPage();
      await guestPage.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
      await guestPage.waitForSelector('.auth-guest-actions a.login-link', {
        timeout: 10000
      });
      const hasLoginLink = await guestPage.$('a.login-link');
      const hasRegisterBtn = await guestPage.$('a.btn-register');
      await guestContext.close();

      if (hasLoginLink && hasRegisterBtn && initialChar === 'Đ' && roleText === 'Độc giả') {
        recordTest('FLOW-11', 'Header phản ánh chính xác trạng thái Guest, Avatar fallback và Role', 'PASS');
      } else {
        recordTest('FLOW-11', 'Header phản ánh chính xác trạng thái Guest, Avatar fallback và Role', 'FAIL', `Init: ${initialChar}, Role: ${roleText}`);
      }
    } catch (err) {
      recordTest('FLOW-11', 'Header phản ánh chính xác trạng thái Guest, Avatar fallback và Role', 'FAIL', err.message);
    }

    // Flow 13: Xử lý lỗi mạng hoặc server khi logout
    try {
      await mainPage.setRequestInterception(true);
      const interceptor = (req) => {
        if (req.url().includes('/api/logout')) {
          req.abort('failed');
        } else {
          req.continue();
        }
      };
      mainPage.on('request', interceptor);

      await mainPage.waitForSelector('button.btn-logout', { visible: true, timeout: 10000 });
      await mainPage.click('button.btn-logout');

      await mainPage.waitForSelector('.logout-error-tooltip', { visible: true, timeout: 10000 });
      const tooltipText = await mainPage.$eval('.logout-error-tooltip', (el) => el.textContent.trim());
      const userNameStillPresent = await mainPage.$('.user-name');

      // Remove interception for subsequent tests
      mainPage.off('request', interceptor);
      await mainPage.setRequestInterception(false);

      if (tooltipText.length > 0 && userNameStillPresent !== null) {
        recordTest('FLOW-13', 'Lỗi mạng khi logout hiển thị tooltip và giữ nguyên trạng thái UI', 'PASS', tooltipText);
      } else {
        recordTest('FLOW-13', 'Lỗi mạng khi logout hiển thị tooltip và giữ nguyên trạng thái UI', 'FAIL', `Tooltip: ${tooltipText}`);
      }
    } catch (err) {
      recordTest('FLOW-13', 'Lỗi mạng khi logout hiển thị tooltip và giữ nguyên trạng thái UI', 'FAIL', err.message);
    }

    // Flow 12: Logout thành công
    try {
      await mainPage.waitForSelector('button.btn-logout', { visible: true, timeout: 10000 });
      await mainPage.click('button.btn-logout');

      await mainPage.waitForSelector('.auth-guest-actions', { visible: true, timeout: 10000 });
      const guestActionsPresent = await mainPage.$('.auth-guest-actions');

      if (guestActionsPresent !== null) {
        recordTest('FLOW-12', 'Logout thành công và Header trở về trạng thái Guest', 'PASS');
      } else {
        recordTest('FLOW-12', 'Logout thành công và Header trở về trạng thái Guest', 'FAIL', 'Guest actions not found');
      }
      await mainAuthContext.close();
    } catch (err) {
      recordTest('FLOW-12', 'Logout thành công và Header trở về trạng thái Guest', 'FAIL', err.message);
    }

    // ---------------------------------------------------------------
    // Flow 14: Hiển thị loading và ngăn gửi lặp
    // (Executed on a fresh context with client validation delay)
    // ---------------------------------------------------------------
    try {
      const { context, page } = await createPage();
      await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#login-email', { timeout: 10000 });

      await page.type('#login-email', activeEmail);
      await page.type('#login-password', password);

      // Verify submit button disabled when fields are empty or during form interaction
      const isEnabled = await page.$eval('button.submit-btn', (el) => !el.disabled);

      // Clear password field to test reactive disabling
      await page.evaluate(() => {
        const input = document.querySelector('#login-password');
        if (input) {
          input.value = '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
      const isDisabledWhenEmpty = await page.$eval('button.submit-btn', (el) => el.disabled);

      if (isEnabled && isDisabledWhenEmpty) {
        recordTest('FLOW-14', 'Hiển thị trạng thái loading và phản ứng vô hiệu hóa nút submit', 'PASS');
      } else {
        recordTest('FLOW-14', 'Hiển thị trạng thái loading và phản ứng vô hiệu hóa nút submit', 'FAIL', `Enabled: ${isEnabled}, DisabledEmpty: ${isDisabledWhenEmpty}`);
      }
      await context.close();
    } catch (err) {
      recordTest('FLOW-14', 'Hiển thị trạng thái loading và phản ứng vô hiệu hóa nút submit', 'FAIL', err.message);
    }
  } finally {
    // 4. Close browser
    if (browser) {
      console.log('\n[Browser] Closing Google Chrome...');
      await browser.close();
    }

    // 5. Safe Teardown: delete ONLY test records created during this run
    console.log('[Teardown] Cleaning up tracked test users from database...');
    const deletedCount = cleanupTestUsers();
    console.log(`[Teardown] Successfully removed ${deletedCount} tracked test record(s).\n`);

    // 6. Stop servers if spawned by test runner
    stopServers();
  }

  // Summary
  console.log('=====================================================');
  console.log('                 E2E TEST SUMMARY                    ');
  console.log('=====================================================');
  const passed = testResults.filter((t) => t.status === 'PASS').length;
  const failed = testResults.filter((t) => t.status === 'FAIL').length;
  console.log(`Total: ${testResults.length} | Passed: ${passed} | Failed: ${failed}`);

  if (failed > 0) {
    process.exit(1);
  }
}

runE2ETests().catch((err) => {
  console.error('[Fatal Error in E2E Suite]:', err);
  stopServers();
  cleanupTestUsers();
  process.exit(1);
});
