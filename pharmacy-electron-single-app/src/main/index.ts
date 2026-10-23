import { join } from 'node:path';

import { app, BrowserWindow, dialog, ipcMain } from 'electron';

import { autoBackupIfDue } from './backup';
import { closeDatabase, openDatabase } from './db/database';
import { handle } from './handlers';
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

  // The app is its own pages only: nothing opens or navigates elsewhere.
  window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  window.webContents.on('will-navigate', (event, url) => {
    if (url !== window.webContents.getURL()) event.preventDefault();
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

ipcMain.handle('call', (_event, name: string, args: unknown[]) => handle(name, args));
ipcMain.handle('store:get', (_event, key: string) => storeGet(key));
ipcMain.handle('store:set', (_event, key: string, value: string) => storeSet(key, value));
ipcMain.handle('store:remove', (_event, key: string) => storeRemove(key));
ipcMain.handle('print', (_event, html: string) => print(html));

// One app at a time on the database.
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    const window = BrowserWindow.getAllWindows()[0];
    if (window?.isMinimized()) window.restore();
    window?.focus();
  });

  void app.whenReady().then(async () => {
    app.setAppUserModelId('com.milesoftwares.pharmacy.single');
    try {
      openDatabase();
    } catch (error) {
      dialog.showErrorBox('Miles Pharmacy cannot open its data', error instanceof Error ? error.message : String(error));
      app.quit();
      return;
    }
    createWindow();
    app.on('activate', () => BrowserWindow.getAllWindows().length === 0 && createWindow());

    await autoBackupIfDue();
    setInterval(() => void autoBackupIfDue(), 60 * 60 * 1000);
  });

  app.on('window-all-closed', () => process.platform !== 'darwin' && app.quit());
  app.on('will-quit', () => closeDatabase());
}
