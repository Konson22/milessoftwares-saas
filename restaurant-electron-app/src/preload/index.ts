import { contextBridge, ipcRenderer } from 'electron';

import type { DesktopBridge } from '../shared/ipc';

const desktop: DesktopBridge = {
  api: (request) => ipcRenderer.invoke('api', request),
  platform: (request) => ipcRenderer.invoke('platform', request),
  signIn: (how) => ipcRenderer.invoke('auth:signIn', how),
  signOut: () => ipcRenderer.invoke('auth:signOut'),
  signedIn: () => ipcRenderer.invoke('auth:signedIn'),
  storeGet: (key) => ipcRenderer.invoke('store:get', key),
  storeSet: (key, value) => ipcRenderer.invoke('store:set', key, value),
  storeRemove: (key) => ipcRenderer.invoke('store:remove', key),
  print: (html) => ipcRenderer.invoke('print', html),
  openExternal: (url) => ipcRenderer.invoke('openExternal', url),
};

contextBridge.exposeInMainWorld('desktop', desktop);
