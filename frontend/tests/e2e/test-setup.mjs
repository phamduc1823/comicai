import { execSync, spawn } from 'node:child_process';
import path from 'node:path';
import net from 'node:net';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const BACKEND_PATH = path.resolve(__dirname, '..', '..', '..', 'backend');
const FRONTEND_PATH = path.resolve(__dirname, '..', '..');

// Track exact IDs of test users created during this test session
export const createdUserIds = [];

let backendProcess = null;
let frontendProcess = null;

export function isPortOpen(port, host = '127.0.0.1') {
  return new Promise((resolve) => {
    const socket = new net.Socket();
    socket.setTimeout(400);
    socket.on('connect', () => {
      socket.destroy();
      resolve(true);
    });
    socket.on('timeout', () => {
      socket.destroy();
      resolve(false);
    });
    socket.on('error', () => {
      socket.destroy();
      resolve(false);
    });
    socket.connect(port, host);
  });
}

async function waitForPort(port, maxWaitMs = 15000) {
  const start = Date.now();
  while (Date.now() - start < maxWaitMs) {
    if (await isPortOpen(port)) {
      return true;
    }
    await new Promise((r) => setTimeout(r, 300));
  }
  return false;
}

/**
 * Ensure both Laravel API (8000) and Vite frontend (5173) are running.
 */
export async function startServers() {
  const isBackendUp = await isPortOpen(8000);
  if (!isBackendUp) {
    backendProcess = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8000'], {
      cwd: BACKEND_PATH,
      stdio: 'ignore',
    });
    const ready = await waitForPort(8000);
    if (!ready) {
      throw new Error('Backend failed to start on port 8000 within 15s');
    }
  }

  const isFrontendUp = await isPortOpen(5173);
  if (!isFrontendUp) {
    frontendProcess = spawn(
      process.execPath,
      ['./node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', '5173'],
      {
        cwd: FRONTEND_PATH,
        stdio: 'ignore',
      }
    );
    const ready = await waitForPort(5173);
    if (!ready) {
      throw new Error('Frontend failed to start on port 5173 within 15s');
    }
  }
}

/**
 * Terminate background servers if they were spawned by this test run.
 */
export function stopServers() {
  if (backendProcess?.pid) {
    try {
      execSync(`taskkill /pid ${backendProcess.pid} /T /F`, { stdio: 'ignore' });
    } catch {}
    backendProcess = null;
  }
  if (frontendProcess?.pid) {
    try {
      execSync(`taskkill /pid ${frontendProcess.pid} /T /F`, { stdio: 'ignore' });
    } catch {}
    frontendProcess = null;
  }
}

/**
 * Safely create a test user via Laravel Tinker and record its primary ID.
 */
export function createTestUser({ name, email, password, role = 'reader', isActive = true }) {
  const phpCode = `$u = App\\Models\\User::create(['name'=>'${name}','email'=>'${email}','password'=>'${password}']); $u->role = '${role}'; $u->is_active = ${isActive ? 'true' : 'false'}; $u->save(); echo $u->id;`;
  const idStr = execSync(`php artisan tinker --execute="${phpCode}"`, {
    cwd: BACKEND_PATH,
    stdio: ['pipe', 'pipe', 'pipe'],
  })
    .toString()
    .trim();

  const id = parseInt(idStr, 10);
  if (!isNaN(id) && id > 0) {
    createdUserIds.push(id);
    return id;
  }
  throw new Error(`Failed to create test user: ${idStr}`);
}

/**
 * Record an existing or newly registered user ID by email for safe cleanup.
 */
export function recordUserByEmail(email) {
  const phpCode = `echo optional(App\\Models\\User::where('email', '${email}')->first())->id;`;
  const idStr = execSync(`php artisan tinker --execute="${phpCode}"`, {
    cwd: BACKEND_PATH,
    stdio: ['pipe', 'pipe', 'pipe'],
  })
    .toString()
    .trim();

  const id = parseInt(idStr, 10);
  if (!isNaN(id) && id > 0) {
    if (!createdUserIds.includes(id)) {
      createdUserIds.push(id);
    }
    return id;
  }
  return null;
}

/**
 * Safely delete ONLY the exact user records created and tracked in this test run.
 * Never deletes records by wildcard or unrecorded IDs.
 */
export function cleanupTestUsers() {
  if (createdUserIds.length === 0) {
    return 0;
  }
  const uniqueIds = [...new Set(createdUserIds)];
  const idsList = uniqueIds.join(',');
  const phpCode = `echo App\\Models\\User::whereIn('id', [${idsList}])->delete();`;
  const deletedCountStr = execSync(`php artisan tinker --execute="${phpCode}"`, {
    cwd: BACKEND_PATH,
    stdio: ['pipe', 'pipe', 'pipe'],
  })
    .toString()
    .trim();

  const count = parseInt(deletedCountStr, 10) || 0;
  createdUserIds.length = 0;
  return count;
}
