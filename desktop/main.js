const { app, BrowserWindow, Menu, ipcMain, dialog } = require('electron');
const path = require('path');
const fs = require('fs');
const os = require('os');
const { pathToFileURL } = require('url');
const { execFile, spawn } = require('child_process');
const { autoUpdater } = require('electron-updater');

const DEFAULT_LARAGON_PATH = 'C:\\laragon\\laragon.exe';
const DEFAULT_SERVER_URL = 'http://360_inmystore.test';
const APP_ICON = path.join(__dirname, 'build', 'icon.ico');

function configPath() {
  return path.join(app.getPath('userData'), 'config.json');
}

function loadConfig() {
  try {
    return JSON.parse(fs.readFileSync(configPath(), 'utf-8'));
  } catch {
    return null;
  }
}

function saveConfig(config) {
  fs.mkdirSync(path.dirname(configPath()), { recursive: true });
  fs.writeFileSync(configPath(), JSON.stringify(config, null, 2));
}

let mainWindow = null;
let setupWindow = null;
let currentConfig = null;

autoUpdater.autoDownload = true;
autoUpdater.autoInstallOnAppQuit = true;

function updatesFeedUrl(config) {
  if (!config || !config.url) return null;
  return `${config.url.replace(/\/+$/, '')}/desktop-updates/`;
}

function checkForUpdates({ manual = false } = {}) {
  if (!app.isPackaged) return; // pas de mise à jour en développement (npm start)
  const feedUrl = updatesFeedUrl(currentConfig);
  if (!feedUrl) return;

  autoUpdater.setFeedURL({ provider: 'generic', url: feedUrl });

  const onError = (err) => {
    if (manual) dialog.showErrorBox('Mise à jour', `Vérification impossible : ${err.message}`);
    cleanup();
  };
  const onNotAvailable = () => {
    if (manual) dialog.showMessageBox({ type: 'info', message: "Vous avez déjà la dernière version." });
    cleanup();
  };
  function cleanup() {
    autoUpdater.removeListener('error', onError);
    autoUpdater.removeListener('update-not-available', onNotAvailable);
  }
  if (manual) {
    autoUpdater.once('error', onError);
    autoUpdater.once('update-not-available', onNotAvailable);
  }

  autoUpdater.checkForUpdates().catch(onError);
}

autoUpdater.on('update-downloaded', () => {
  dialog
    .showMessageBox({
      type: 'info',
      title: 'Mise à jour disponible',
      message: "Une nouvelle version de l'application est prête. Redémarrer maintenant pour l'installer ?",
      buttons: ['Redémarrer maintenant', 'Plus tard'],
      defaultId: 0,
      cancelId: 1,
    })
    .then(({ response }) => {
      if (response === 0) autoUpdater.quitAndInstall();
    });
});

autoUpdater.on('error', (err) => {
  console.error('Auto-update error:', err);
});

function openSetupWindow() {
  if (setupWindow) {
    setupWindow.focus();
    return;
  }
  setupWindow = new BrowserWindow({
    width: 560,
    height: 560,
    resizable: false,
    autoHideMenuBar: true,
    icon: APP_ICON,
    title: '360inmystore — Configuration du poste',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });
  setupWindow.setMenuBarVisibility(false);
  setupWindow.loadFile(path.join(__dirname, 'renderer', 'settings.html'));
  setupWindow.on('closed', () => {
    setupWindow = null;
  });
}

function buildMenu() {
  const template = [
    {
      label: 'Fichier',
      submenu: [
        { label: 'Paramètres du poste…', click: () => openSetupWindow() },
        {
          label: 'Recharger',
          accelerator: 'CmdOrCtrl+R',
          click: () => mainWindow && mainWindow.reload(),
        },
        { label: 'Vérifier les mises à jour…', click: () => checkForUpdates({ manual: true }) },
        { type: 'separator' },
        { role: 'quit', label: 'Quitter' },
      ],
    },
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

function isProcessRunning(imageName) {
  return new Promise((resolve) => {
    execFile('tasklist', ['/fi', `imagename eq ${imageName}`], (err, stdout) => {
      resolve(Boolean(!err && stdout && stdout.toLowerCase().includes(imageName.toLowerCase())));
    });
  });
}

async function ensureLaragonRunning(laragonPath) {
  if (await isProcessRunning('httpd.exe')) return true;

  const exe = laragonPath || DEFAULT_LARAGON_PATH;
  if (!fs.existsSync(exe)) return false;

  spawn(exe, [], { detached: true, stdio: 'ignore' }).unref();

  for (let attempt = 0; attempt < 20; attempt += 1) {
    await new Promise((resolve) => setTimeout(resolve, 1000));
    if (await isProcessRunning('httpd.exe')) return true;
  }
  return false;
}

function showMessage(win, title, message, { showSettingsButton = true } = {}) {
  const html = `
    <html>
      <head><meta charset="utf-8" /></head>
      <body style="font-family:Segoe UI,sans-serif;text-align:center;margin-top:18vh;background:#1e1e1e;color:#eee;">
        <h2>${title}</h2>
        <p style="max-width:480px;margin:0 auto 20px;color:#ccc;">${message}</p>
        <div style="display:flex;gap:12px;justify-content:center;">
          <button onclick="location.reload()" style="padding:10px 20px;font-size:14px;cursor:pointer;">Réessayer</button>
          ${
            showSettingsButton
              ? '<button id="inmystore-settings-btn" style="padding:10px 20px;font-size:14px;cursor:pointer;background:#2563eb;color:#fff;border:none;border-radius:4px;">Modifier l\'adresse du serveur</button>'
              : ''
          }
        </div>
        <script>
          const btn = document.getElementById('inmystore-settings-btn');
          if (btn && window.inmystore) {
            btn.addEventListener('click', () => window.inmystore.openSettings());
          }
        </script>
      </body>
    </html>
  `;
  win.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`);
}

async function openMainWindow(config) {
  currentConfig = config;

  if (mainWindow) {
    mainWindow.close();
    mainWindow = null;
  }

  mainWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    autoHideMenuBar: false,
    icon: APP_ICON,
    title: '360inmystore',
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      // Sans ça, un <iframe> caché pointé sur une URL PDF (impression
      // "Facture"/devis, voir bouton-imprimer.blade.php) ne peut pas
      // afficher le PDF — l'impression retombait alors sur la page visible
      // derrière au lieu du PDF réel.
      plugins: true,
    },
  });

  mainWindow.once('ready-to-show', () => mainWindow && mainWindow.show());
  mainWindow.on('closed', () => {
    mainWindow = null;
  });

  const targetUrl = config.url || DEFAULT_SERVER_URL;

  if (config.role === 'server') {
    const ok = await ensureLaragonRunning(config.laragonPath);
    if (!ok) {
      showMessage(
        mainWindow,
        'Laragon introuvable ou non démarré',
        `Impossible de démarrer Laragon automatiquement (${config.laragonPath || DEFAULT_LARAGON_PATH}). Démarrez-le manuellement puis cliquez sur Réessayer.`
      );
      return;
    }
  }

  mainWindow.webContents.on('did-fail-load', (_event, errorCode, errorDescription, validatedURL) => {
    if (errorCode === -3) return; // ERR_ABORTED — navigation replaced on purpose
    if (validatedURL && validatedURL.startsWith('data:')) return;
    showMessage(
      mainWindow,
      'Serveur injoignable',
      `${targetUrl} n'a pas répondu (${errorDescription}). Vérifiez le réseau et que le poste serveur est bien démarré, puis réessayez.`
    );
  });

  // Le contenu chargé dans cette fenêtre hérite du pont privilégié
  // window.inmystore (preload.js) — sans restriction, une page usurpée (lien
  // externe, MITM réseau local) pourrait l'utiliser pour repointer
  // durablement l'app (saveConfig) ou déclencher une impression arbitraire.
  // On n'autorise jamais de navigation hors de l'origine du serveur
  // configuré, ni l'ouverture de nouvelles fenêtres depuis le contenu web.
  const origineAutorisee = (() => {
    try {
      return new URL(targetUrl).origin;
    } catch {
      return null;
    }
  })();

  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (url.startsWith('data:')) return; // nos propres écrans d'erreur/message
    try {
      if (!origineAutorisee || new URL(url).origin !== origineAutorisee) {
        event.preventDefault();
      }
    } catch {
      event.preventDefault();
    }
  });

  mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));

  mainWindow.webContents.once('did-finish-load', () => {
    setTimeout(() => checkForUpdates(), 5000);
  });

  mainWindow.loadURL(targetUrl);
}

ipcMain.handle('inmystore:get-config', () => loadConfig());
ipcMain.handle('inmystore:defaults', () => ({
  laragonPath: DEFAULT_LARAGON_PATH,
  serverUrl: DEFAULT_SERVER_URL,
}));
ipcMain.handle('inmystore:open-settings', () => openSetupWindow());
let previewWindow = null;

function openPdfPreview(url, title) {
  if (previewWindow && !previewWindow.isDestroyed()) {
    previewWindow.close();
  }
  previewWindow = new BrowserWindow({
    width: 900,
    height: 1000,
    icon: APP_ICON,
    title: title || 'Aperçu avant impression',
    webPreferences: {
      // Active le lecteur PDF intégré de Chromium (aperçu + son propre
      // bouton Imprimer/Télécharger), pour rester dans l'application au
      // lieu d'ouvrir un programme externe.
      plugins: true,
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });
  previewWindow.setMenuBarVisibility(false);
  previewWindow.loadURL(url);
  previewWindow.on('closed', () => {
    previewWindow = null;
  });
}

ipcMain.handle('inmystore:print-pdf-url', (_event, url) => {
  // Facture/devis/bon d'achat : on affiche directement le PDF déjà généré
  // par le serveur (dompdf, route .pdf) dans sa propre fenêtre — plus fiable
  // qu'un <iframe> caché + printToPDF() de la fenêtre principale, qui ne
  // ciblait pas correctement le cadre du PDF (l'impression retombait sur la
  // page visible derrière au lieu du PDF réel).
  if (!url) return { success: false, reason: 'no-url' };

  // Ne jamais ouvrir une URL hors de l'origine du serveur configuré (ex.
  // file:// arbitraire) — le renderer fournit cette URL, elle ne doit pas
  // être une confiance aveugle même si le contenu vient normalement de
  // notre propre app.
  try {
    const origineServeur = currentConfig?.url ? new URL(currentConfig.url).origin : null;
    if (!origineServeur || new URL(url).origin !== origineServeur) {
      return { success: false, reason: 'origine-non-autorisee' };
    }
  } catch {
    return { success: false, reason: 'url-invalide' };
  }

  openPdfPreview(url, mainWindow ? mainWindow.getTitle() : undefined);
  return { success: true };
});

ipcMain.handle('inmystore:print', async () => {
  if (!mainWindow) return { success: false, reason: 'no-window' };
  // webContents.print() ouvre bien le dialogue Windows natif, mais son
  // panneau d'aperçu reste vide (Electron ne fournit pas les données que
  // Windows attend pour le générer — limitation connue, sans correctif côté
  // application). On génère donc un PDF et on l'affiche dans une fenêtre de
  // l'app via le lecteur PDF intégré de Chromium : vrai aperçu, sans quitter
  // l'application.
  try {
    const pdfBuffer = await mainWindow.webContents.printToPDF({
      printBackground: true,
      preferCSSPageSize: true,
      // Sans ça, Electron ajoute ses propres marges par défaut (~2,5 cm) et
      // ignore le `@page { margin: 5mm }` du CSS d'impression (ticket 80mm,
      // resources/sass/app.scss) : la mise en forme ressemblait alors à un
      // export PDF générique au lieu du ticket/facture prévu.
      margins: { marginType: 'none' },
    });
    const filePath = path.join(os.tmpdir(), `360inmystore-impression-${Date.now()}.pdf`);
    fs.writeFileSync(filePath, pdfBuffer);
    openPdfPreview(pathToFileURL(filePath).toString(), mainWindow.getTitle());
    return { success: true };
  } catch (err) {
    dialog.showErrorBox('Impression', `Impossible de générer l'aperçu : ${err.message}`);
    return { success: false, reason: err.message };
  }
});
ipcMain.handle('inmystore:save-config', async (_event, config) => {
  saveConfig(config);
  if (setupWindow) {
    setupWindow.close();
    setupWindow = null;
  }
  await openMainWindow(config);
  return true;
});

app.whenReady().then(async () => {
  buildMenu();
  const config = loadConfig();
  if (!config) {
    openSetupWindow();
  } else {
    await openMainWindow(config);
  }
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
