import { execSync, spawn } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';
import net from 'node:net';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const BACKEND_PATH = path.resolve(__dirname, '..', '..', '..', 'backend');
const FRONTEND_PATH = path.resolve(__dirname, '..', '..');
const SCRATCH_PATH = path.resolve(__dirname, '..', '..', '..', 'scratch');

if (!fs.existsSync(SCRATCH_PATH)) {
  fs.mkdirSync(SCRATCH_PATH, { recursive: true });
}

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

export async function waitForPort(port, maxWaitMs = 20000) {
  const start = Date.now();
  while (Date.now() - start < maxWaitMs) {
    if (await isPortOpen(port)) {
      return true;
    }
    await new Promise((r) => setTimeout(r, 400));
  }
  return false;
}

/**
 * Start backend with APP_ENV=e2e and frontend Vite
 */
export async function startE2EServers() {
  const isBackendUp = await isPortOpen(8000);
  if (!isBackendUp) {
    console.log('[Setup] Starting PHP server on 127.0.0.1:8000 with APP_ENV=e2e ...');
    backendProcess = spawn(
      'php',
      ['-S', '127.0.0.1:8000', '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
      {
        cwd: path.resolve(BACKEND_PATH, 'public'),
        env: { ...process.env, APP_ENV: 'e2e' },
        stdio: 'ignore',
      }
    );
    const ready = await waitForPort(8000);
    if (!ready) {
      throw new Error('Backend failed to start on port 8000 within 20s');
    }
    console.log('[Setup] PHP server running on port 8000 (APP_ENV=e2e).');
  } else {
    console.log('[Setup] Port 8000 already active.');
  }

  const isFrontendUp = await isPortOpen(5173);
  if (!isFrontendUp) {
    console.log('[Setup] Starting Vite dev server on 127.0.0.1:5173 ...');
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
      throw new Error('Frontend failed to start on port 5173 within 20s');
    }
    console.log('[Setup] Vite frontend running on port 5173.');
  } else {
    console.log('[Setup] Port 5173 already active.');
  }
}

/**
 * Terminate background servers if spawned by this session
 */
export function stopE2EServers() {
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
 * Execute raw PHP code within Laravel application context with APP_ENV=e2e
 */
export function executePhpCode(code) {
  const tmpFile = path.resolve(SCRATCH_PATH, `e2e_cmd_${Date.now()}_${Math.floor(Math.random() * 1000)}.php`);
  const fullPhp = `<?php
$backendPath = 'd:/Study/UTC/ky4/doan/comicai/backend';
require $backendPath . '/vendor/autoload.php';
putenv('APP_ENV=e2e');
\$_ENV['APP_ENV'] = 'e2e';
\$_SERVER['APP_ENV'] = 'e2e';
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
