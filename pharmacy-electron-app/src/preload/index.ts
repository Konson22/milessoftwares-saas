import { contextBridge, ipcRenderer } from 'electron';

import type { DesktopBridge } from '../shared/ipc';

const desktop: DesktopBridge = {
  api: (request) => ipcRenderer.invoke('api', request),
  signIn: (how, product) => ipcRenderer.invoke('auth:signIn', how, product),
  signOut: () => ipcRenderer.invoke('auth:signOut'),
  signedIn: () => ipcRenderer.invoke('auth:signedIn'),
  storeGet: (key) => ipcRenderer.invoke('store:get', key),
  storeSet: (key, value) => ipcRenderer.invoke('store:set', key, value),
  storeRemove: (key) => ipcRenderer.invoke('store:remove', key),
  print: (html) => ipcRenderer.invoke('print', html),
  openExternal: (url) => ipcRenderer.invoke('openExternal', url),
  netOnline: () => ipcRenderer.invoke('netOnline'),
};

contextBridge.exposeInMainWorld('desktop', desktop);
