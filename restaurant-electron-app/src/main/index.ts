import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import { app, BrowserWindow, ipcMain, shell } from 'electron';

import type { ApiRequest, PlatformRequest, SignInWith } from '../shared/ipc';
import { signedIn, signIn, signOut } from './auth';
import { apiRequest, platformRequest } from './http';
import { storeGet, storeRemove, storeSet } from './store';

function createWindow() {
  const window = new BrowserWindow({
    width: 1360,
    height: 860,
    minWidth: 1024,
    minHeight: 640,
    show: false,
    autoHideMenuBar: true,
    title: 'Miles Restaurant',
    backgroundColor: '#f4f5f8',
    webPreferences: {
      preload: join(__dirname, '../preload/index.js'),
      // The preload only bridges IPC: nothing of Node is needed in the window.
      sandbox: true,
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  window.once('ready-to-show', () => window.show());

  // Links (the web back office) open in the browser, never in the app.
  window.webContents.setWindowOpenHandler(({ url }) => {
    if (/^https?:/.test(url)) void shell.openExternal(url);
    return { action: 'deny' };
  });

  // The window only ever shows the app itself: any other page would get the
  // desktop bridge, and with it the member's sign-in. Links go to the browser.
  const home = window.webContents;
  home.on('will-navigate', (event, url) => {
    if (isAppUrl(url)) return;
    event.preventDefault();
    if (/^https?:/.test(url)) void shell.openExternal(url);
  });
  home.on('will-redirect', (event, url) => {
    if (!isAppUrl(url)) event.preventDefault();
  });
  home.on('will-attach-webview', (event) => event.preventDefault());

  if (!app.isPackaged && process.env.ELECTRON_RENDERER_URL) {
    void window.loadURL(process.env.ELECTRON_RENDERER_URL);
  } else {
    void window.loadFile(join(__dirname, '../renderer/index.html'));
  }
}

/** The app's own pages: the packaged files, or the dev server while developing. */
function isAppUrl(url: string): boolean {
  const dev = !app.isPackaged ? process.env.ELECTRON_RENDERER_URL : undefined;
  if (dev && url.startsWith(dev)) return true;
  return url.startsWith(pathToFileURL(join(__dirname, '../renderer/')).href);
}

/** A bill in a hidden window, through the system print dialog. */
function print(html: string): Promise<boolean> {
  const window = new BrowserWindow({ show: false, webPreferences: { sandbox: true, javascript: false } });
  return new Promise((resolve) => {
    window.webContents.once('did-finish-load', () => {
      window.webContents.print({ silent: false, printBackground: true }, (success) => {
        window.destroy();
        resolve(success);
      });
    });
    void window.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`);
  });
}

ipcMain.handle('api', (_event, request: ApiRequest) => apiRequest(request));
ipcMain.handle('platform', (_event, request: PlatformRequest) => platformRequest(request));
ipcMain.handle('auth:signIn', (_event, how: SignInWith) => signIn(how));
ipcMain.handle('auth:signOut', () => signOut());
ipcMain.handle('auth:signedIn', () => signedIn());
ipcMain.handle('store:get', (_event, key: string) => storeGet(key));
ipcMain.handle('store:set', (_event, key: string, value: string) => storeSet(key, value));
ipcMain.handle('store:remove', (_event, key: string) => storeRemove(key));
ipcMain.handle('print', (_event, html: string) => print(html));
ipcMain.handle('openExternal', (_event, url: string) => (/^https?:/.test(url) ? shell.openExternal(url) : undefined));

// One window at a time: a second start brings the first one forward.
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    const window = BrowserWindow.getAllWindows()[0];
    if (window?.isMinimized()) window.restore();
    window?.focus();
  });

  void app.whenReady().then(() => {
    app.setAppUserModelId('com.milesoftwares.restaurant.desktop');
    createWindow();
    app.on('activate', () => BrowserWindow.getAllWindows().length === 0 && createWindow());
  });

  app.on('window-all-closed', () => process.platform !== 'darwin' && app.quit());
}
