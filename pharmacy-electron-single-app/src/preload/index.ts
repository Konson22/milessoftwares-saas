import { contextBridge, ipcRenderer } from 'electron';

import type { DesktopBridge } from '../shared/ipc';

const desktop: DesktopBridge = {
  call: (name, ...args) => ipcRenderer.invoke('call', name, args),
  storeGet: (key) => ipcRenderer.invoke('store:get', key),
  storeSet: (key, value) => ipcRenderer.invoke('store:set', key, value),
  storeRemove: (key) => ipcRenderer.invoke('store:remove', key),
  print: (html) => ipcRenderer.invoke('print', html),
};

contextBridge.exposeInMainWorld('desktop', desktop);
