import { join } from 'node:path';

import { app, BrowserWindow, ipcMain, net, shell } from 'electron';

import type { ApiRequest, PlatformRequest, Product, SignInWith } from '../shared/ipc';
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
    title: 'Miles Pharmacy',
    backgroundColor: '#f4f5f8',
    webPreferences: {
      preload: join(__dirname, '../preload/index.js'),
      sandbox: false,
      contextIsolation: true,
    },
  });

  window.once('ready-to-show', () => window.show());

  // Links (the web back office) open in the browser, never in the app.
  window.webContents.setWindowOpenHandler(({ url }) => {
    if (/^https?:/.test(url)) void shell.openExternal(url);
    return { action: 'deny' };
  });

  if (!app.isPackaged && process.env.ELECTRON_RENDERER_URL) {
    void window.loadURL(process.env.ELECTRON_RENDERER_URL);
  } else {
    void window.loadFile(join(__dirname, '../renderer/index.html'));
  }
}

/** A receipt in a hidden window, through the system print dialog. */
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
ipcMain.handle('platform', (_event, request: PlatformRequest) => platformRequest({ ...request, product: request.product === 'retail' ? 'retail' : 'pharmacy' }));
ipcMain.handle('auth:signIn', (_event, how: SignInWith, product: Product) => signIn(how, product === 'retail' ? 'retail' : 'pharmacy'));
ipcMain.handle('auth:signOut', () => signOut());
ipcMain.handle('auth:signedIn', () => signedIn());
ipcMain.handle('store:get', (_event, key: string) => storeGet(key));
ipcMain.handle('store:set', (_event, key: string, value: string) => storeSet(key, value));
ipcMain.handle('store:remove', (_event, key: string) => storeRemove(key));
ipcMain.handle('print', (_event, html: string) => print(html));
ipcMain.handle('openExternal', (_event, url: string) => (/^https?:/.test(url) ? shell.openExternal(url) : undefined));
ipcMain.handle('netOnline', () => net.isOnline());

// One counter app at a time: two would send the same queue twice over.
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    const window = BrowserWindow.getAllWindows()[0];
    if (window?.isMinimized()) window.restore();
    window?.focus();
  });

  void app.whenReady().then(() => {
    app.setAppUserModelId('com.milesoftwares.pharmacy.desktop');
    createWindow();
    app.on('activate', () => BrowserWindow.getAllWindows().length === 0 && createWindow());
  });

  app.on('window-all-closed', () => process.platform !== 'darwin' && app.quit());
}
