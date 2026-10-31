import { join } from 'node:path';

import { app, BrowserWindow, ipcMain, net, shell } from 'electron';

import type { ApiRequest, Mode, PlatformRequest, PrintLine, Product, SignInWith } from '../shared/ipc';
import { signedIn, signIn, signOut } from './auth';
import { autoBackupIfDue } from './backup';
import { closeDatabase, isDatabaseOpen, openDatabase } from './db/database';
import { signOut as signOutHere } from './db/session';
import { handle } from './handlers';
import { apiRequest, platformRequest } from './http';
import { defaultMode, importLegacyOffline } from './legacy';
import { getMode, setMode } from './mode';
import { print } from './printer';
import { storeGet, storeRemove, storeSet } from './store';

function createWindow() {
  const window = new BrowserWindow({
    width: 1360,
    height: 860,
    minWidth: 1024,
    minHeight: 640,
    show: false,
    autoHideMenuBar: true,
    title: 'Miles Pharmacy',
    backgroundColor: '#f4f5f8',
    webPreferences: {
      preload: join(__dirname, '../preload/index.js'),
      sandbox: false,
      contextIsolation: true,
    },
  });

  window.once('ready-to-show', () => window.show());

  // The app is its own pages only. Links (the web back office) open in the browser.
  window.webContents.setWindowOpenHandler(({ url }) => {
    if (/^https?:/.test(url)) void shell.openExternal(url);
    return { action: 'deny' };
  });
  window.webContents.on('will-navigate', (event, url) => {
    if (url !== window.webContents.getURL()) event.preventDefault();
  });

  if (!app.isPackaged && process.env.ELECTRON_RENDERER_URL) {
    void window.loadURL(process.env.ELECTRON_RENDERER_URL);
  } else {
    void window.loadFile(join(__dirname, '../renderer/index.html'));
  }
}

/** Offline: the day's copy of the pharmacy, when it is due. A database that will not open says so on the next call. */
async function backupIfDue() {
  if (getMode() !== 'offline') return;
  try {
    if (!isDatabaseOpen()) openDatabase();
  } catch (error) {
    console.error('The pharmacy on this PC could not be opened', error);
    return;
  }
  await autoBackupIfDue();
}

ipcMain.handle('mode:get', () => getMode());
ipcMain.handle('mode:set', (_event, mode: Mode | null) => {
  const next = mode === 'offline' || mode === 'hybrid' ? mode : null;
  // Whoever was signed in to the pharmacy on this PC is no longer.
  if (next !== 'offline') signOutHere();
  setMode(next);
  if (next === 'offline') void backupIfDue();
});
ipcMain.handle('call', (_event, name: string, args: unknown[]) => handle(name, args));
ipcMain.handle('api', (_event, request: ApiRequest) => apiRequest(request));
ipcMain.handle('platform', (_event, request: PlatformRequest) => platformRequest({ ...request, product: request.product === 'retail' ? 'retail' : 'pharmacy' }));
ipcMain.handle('auth:signIn', (_event, how: SignInWith, product: Product) => signIn(how, product === 'retail' ? 'retail' : 'pharmacy'));
ipcMain.handle('auth:signOut', () => signOut());
ipcMain.handle('auth:signedIn', () => signedIn());
ipcMain.handle('store:get', (_event, key: string) => storeGet(key));
ipcMain.handle('store:set', (_event, key: string, value: string) => storeSet(key, value));
ipcMain.handle('store:remove', (_event, key: string) => storeRemove(key));
ipcMain.handle('print', (_event, html: string, lines?: PrintLine[]) => print(html, Array.isArray(lines) ? lines : undefined));
ipcMain.handle('openExternal', (_event, url: string) => (/^https?:/.test(url) ? shell.openExternal(url) : undefined));
ipcMain.handle('netOnline', () => net.isOnline());

// One app at a time: two would open the same database, or send the same queue twice over.
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    const window = BrowserWindow.getAllWindows()[0];
    if (window?.isMinimized()) window.restore();
    window?.focus();
  });

  void app.whenReady().then(async () => {
    app.setAppUserModelId('com.milesoftwares.pharmacy.desktop');
    try {
      importLegacyOffline();
    } catch (error) {
      console.error('Copying the pharmacy from Miles Pharmacy Offline failed', error);
    }
    defaultMode();
    createWindow();
    app.on('activate', () => BrowserWindow.getAllWindows().length === 0 && createWindow());

    await backupIfDue();
    setInterval(() => void backupIfDue(), 60 * 60 * 1000);
  });

  app.on('window-all-closed', () => process.platform !== 'darwin' && app.quit());
  app.on('will-quit', () => closeDatabase());
}
