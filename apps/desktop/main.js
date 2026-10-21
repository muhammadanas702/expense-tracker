const { app, BrowserWindow, Menu, shell, dialog, ipcMain, net } = require('electron');
const path = require('path');
const fs = require('fs');
const os = require('os');
const { spawn } = require('child_process');

const HOST = 'expenseflow.rf.gd';
const HOME = 'https://' + HOST + '/auth/login.php';
const RELEASE_API = 'https://api.github.com/repos/muhammadanas702/expense-tracker/releases/latest';
let win = null;

if (!app.requestSingleInstanceLock()) { app.quit(); }
app.on('second-instance', () => { if (win) { if (win.isMinimized()) win.restore(); win.focus(); } });

const parts = (v) => String(v).replace(/[^0-9.]/g, '').split('.').map((n) => parseInt(n, 10) || 0);
function isNewer(latest, mine) {
  const a = parts(latest), b = parts(mine);
  for (let i = 0; i < 3; i++) { if ((a[i] || 0) !== (b[i] || 0)) return (a[i] || 0) > (b[i] || 0); }
  return false;
}

async function checkForUpdate(manual) {
  try {
    const res = await net.fetch(RELEASE_API, { headers: { Accept: 'application/vnd.github+json' } });
    if (!res.ok) throw new Error('http ' + res.status);
    const rel = await res.json();
    const asset = (rel.assets || []).find((a) => /\.exe$/i.test(a.name));
    if (!asset || !isNewer(rel.tag_name, app.getVersion())) {
      if (manual) dialog.showMessageBox(win, { type: 'info', message: 'You have the latest version (' + app.getVersion() + ').' });
      return;
    }
    const r = await dialog.showMessageBox(win, {
      type: 'info', title: 'Update available',
      message: 'A new version of ExpenseFlow (' + rel.tag_name + ') is ready.',
      detail: 'You have ' + app.getVersion() + '. Download the installer and run it; your data stays.',
      buttons: ['Update now', 'Later'], defaultId: 0, cancelId: 1,
    });
    if (r.response === 0) await installUpdate(asset.browser_download_url);
  } catch (e) {
    if (manual) dialog.showMessageBox(win, { type: 'warning', message: 'Could not check for updates right now.' });
  }
}

// Downloads the new installer, closes the app, runs the installer silently and starts the new version.
async function installUpdate(url) {
  try {
    win.setTitle('ExpenseFlow - downloading update...');
    win.setProgressBar(2); // indeterminate
    const res = await net.fetch(url);
    if (!res.ok) throw new Error('http ' + res.status);
    const file = path.join(os.tmpdir(), 'ExpenseFlow-Setup-update.exe');
    await fs.promises.writeFile(file, Buffer.from(await res.arrayBuffer()));
    spawn(file, ['/S', '--force-run'], { detached: true, stdio: 'ignore' }).unref();
    app.quit();
  } catch (e) {
    win.setProgressBar(-1);
    win.setTitle('ExpenseFlow');
    dialog.showMessageBox(win, { type: 'warning', message: 'The update could not be downloaded.', detail: 'You can download it from the Releases page instead.' });
    shell.openExternal('https://github.com/muhammadanas702/expense-tracker/releases/latest');
  }
}

function createWindow() {
  win = new BrowserWindow({
    width: 1280, height: 820, minWidth: 380, minHeight: 600,
    backgroundColor: '#EAF4FF', title: 'ExpenseFlow', show: false,
    icon: path.join(__dirname, 'build', 'icon.ico'),
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: true },
  });
  win.once('ready-to-show', () => win.show());
  win.webContents.setUserAgent(win.webContents.getUserAgent() + ' ExpenseFlowDesktop/' + app.getVersion());

  // Only the ExpenseFlow site opens inside the app; everything else goes to the normal browser.
  const inside = (u) => { try { return new URL(u).hostname === HOST; } catch (e) { return false; } };
  win.webContents.setWindowOpenHandler(({ url }) => { if (inside(url)) return { action: 'allow' }; shell.openExternal(url); return { action: 'deny' }; });
  win.webContents.on('will-navigate', (e, url) => { if (!inside(url) && !url.startsWith('file:')) { e.preventDefault(); shell.openExternal(url); } });

  // No internet: the site's own offline pages (service worker) show saved data; otherwise a simple offline screen.
  win.webContents.on('did-fail-load', (e, code, desc, url, isMain) => {
    if (isMain && code !== -3) win.loadFile(path.join(__dirname, 'offline.html'));
  });
  win.loadURL(HOME);
}

app.whenReady().then(() => {
  ipcMain.on('ef-version', (e) => { e.returnValue = app.getVersion(); });
  ipcMain.on('ef-check-update', () => checkForUpdate(true));
  Menu.setApplicationMenu(Menu.buildFromTemplate([
    { label: 'App', submenu: [
      { label: 'Home', accelerator: 'CmdOrCtrl+H', click: () => win && win.loadURL(HOME) },
      { label: 'Reload', accelerator: 'CmdOrCtrl+R', click: () => win && win.webContents.reload() },
      { label: 'Check for updates', click: () => checkForUpdate(true) },
      { type: 'separator' }, { role: 'quit', label: 'Exit' } ] },
    { label: 'View', submenu: [{ role: 'resetZoom' }, { role: 'zoomIn' }, { role: 'zoomOut' }, { role: 'togglefullscreen' }] },
  ]));
  createWindow();
  setTimeout(() => checkForUpdate(false), 4000);
});
app.on('window-all-closed', () => app.quit());
