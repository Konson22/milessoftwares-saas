import { contextBridge, ipcRenderer } from 'electron';

import type { DesktopBridge } from '../shared/ipc';

const desktop: DesktopBridge = {
  getMode: () => ipcRenderer.invoke('mode:get'),
  setMode: (mode) => ipcRenderer.invoke('mode:set', mode),
  call: (name, ...args) => ipcRenderer.invoke('call', name, args),
  api: (request) => ipcRenderer.invoke('api', request),
  platform: (request) => ipcRenderer.invoke('platform', request),
  signIn: (how, product) => ipcRenderer.invoke('auth:signIn', how, product),
  signOut: () => ipcRenderer.invoke('auth:signOut'),
  signedIn: () => ipcRenderer.invoke('auth:signedIn'),
  storeGet: (key) => ipcRenderer.invoke('store:get', key),
  storeSet: (key, value) => ipcRenderer.invoke('store:set', key, value),
  storeRemove: (key) => ipcRenderer.invoke('store:remove', key),
  print: (html, lines) => ipcRenderer.invoke('print', html, lines),
  openExternal: (url) => ipcRenderer.invoke('openExternal', url),
  netOnline: () => ipcRenderer.invoke('netOnline'),
};

contextBridge.exposeInMainWorld('desktop', desktop);
