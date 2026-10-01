const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('inmystore', {
  getConfig: () => ipcRenderer.invoke('inmystore:get-config'),
  getDefaults: () => ipcRenderer.invoke('inmystore:defaults'),
  saveConfig: (config) => ipcRenderer.invoke('inmystore:save-config', config),
  openSettings: () => ipcRenderer.invoke('inmystore:open-settings'),
  print: () => ipcRenderer.invoke('inmystore:print'),
  printPdfUrl: (url) => ipcRenderer.invoke('inmystore:print-pdf-url', url),
});
