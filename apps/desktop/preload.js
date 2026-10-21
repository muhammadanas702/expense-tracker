const { contextBridge, ipcRenderer } = require('electron');
const ver = ipcRenderer.sendSync('ef-version');
// Lets the Profile page show the installed version and run "Check for updates".
contextBridge.exposeInMainWorld('EFApp', {
  version: () => ver,
  checkUpdate: () => ipcRenderer.send('ef-check-update'),
});
