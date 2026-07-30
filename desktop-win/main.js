/**
 * غلاف ويندوز أصلي (Electron) — يتحكّم بأزرار إطار النافذة نفسها
 * (تصغير / إغلاق X) وليس من صفحة الويب.
 */
const { app, BrowserWindow, dialog, shell, screen } = require('electron');
const path = require('path');
const fs = require('fs');

function loadConfig() {
  const configPath = path.join(__dirname, 'config.json');
  const defaults = {
    appUrl: 'http://localhost/manager/login.php',
    windowTitle: 'النظام المحاسبي',
    minimizable: false,
    confirmOnClose: true,
    disableCloseButton: false,
    startMaximized: true,
  };
  try {
    const raw = fs.readFileSync(configPath, 'utf8');
    return Object.assign({}, defaults, JSON.parse(raw));
  } catch (e) {
    return defaults;
  }
}

const config = loadConfig();
let mainWindow = null;
let allowQuit = false;

function primaryWorkArea() {
  try {
    const display = screen.getDisplayNearestPoint(screen.getCursorScreenPoint());
    return display.workArea;
  } catch (e) {
    return screen.getPrimaryDisplay().workArea;
  }
}

function ensureMaximized() {
  if (!mainWindow || mainWindow.isDestroyed()) {
    return;
  }
  if (config.startMaximized === false) {
    return;
  }
  try {
    if (!mainWindow.isMaximized()) {
      mainWindow.maximize();
    }
  } catch (e) {
    /* ignore */
  }
}

function createWindow() {
  const disableClose = !!config.disableCloseButton;
  const confirmClose = config.confirmOnClose !== false && !disableClose;
  const work = primaryWorkArea();

  mainWindow = new BrowserWindow({
    x: work.x,
    y: work.y,
    width: Math.max(1024, work.width),
    height: Math.max(700, work.height),
    minWidth: 1024,
    minHeight: 700,
    show: false,
    autoHideMenuBar: true,
    title: config.windowTitle || 'النظام المحاسبي',
    backgroundColor: '#1e3a5f',
    minimizable: config.minimizable !== false ? true : false,
    maximizable: true,
    closable: !disableClose,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      backgroundThrottling: false,
      spellcheck: false,
    },
  });

  // املأ مساحة العمل ثم كبّر قبل أي عرض — يتجنّب نافذة صغيرة عند الفتح البطيء للسيرفر
  try {
    mainWindow.setBounds(work);
  } catch (e) {
    /* ignore */
  }
  ensureMaximized();

  mainWindow.once('ready-to-show', () => {
    ensureMaximized();
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.show();
      mainWindow.focus();
    }
    setTimeout(ensureMaximized, 40);
    setTimeout(ensureMaximized, 250);
  });

  mainWindow.webContents.on('did-finish-load', () => {
    ensureMaximized();
  });

  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    try {
      const base = new URL(config.appUrl);
      const next = new URL(url);
      if (next.origin === base.origin) {
        return { action: 'allow' };
      }
    } catch (e) {
      /* ignore */
    }
    shell.openExternal(url);
    return { action: 'deny' };
  });

  mainWindow.on('page-title-updated', (e) => {
    e.preventDefault();
    if (mainWindow) {
      mainWindow.setTitle(config.windowTitle || 'النظام المحاسبي');
    }
  });

  mainWindow.on('unmaximize', () => {
    // الإبقاء على وضع التكبير حتى لا ينكسر التخطيط
    if (config.startMaximized === false) {
      return;
    }
    setTimeout(ensureMaximized, 0);
  });

  mainWindow.on('close', (e) => {
    if (allowQuit || !mainWindow) {
      return;
    }

    if (disableClose) {
      e.preventDefault();
      dialog.showMessageBox(mainWindow, {
        type: 'info',
        buttons: ['حسناً'],
        defaultId: 0,
        title: config.windowTitle || 'النظام المحاسبي',
        message: 'لا يمكن إغلاق النافذة من هنا.',
        detail: 'استخدم «تسجيل خروج» من داخل النظام للخروج بأمان.',
      });
      return;
    }

    if (!confirmClose) {
      return;
    }

    e.preventDefault();
    const result = dialog.showMessageBoxSync(mainWindow, {
      type: 'question',
      buttons: ['نعم، خروج', 'إلغاء'],
      defaultId: 1,
      cancelId: 1,
      noLink: true,
      title: 'تأكيد الخروج',
      message: 'هل تريد الخروج من النظام؟',
    });

    if (result === 0) {
      allowQuit = true;
      mainWindow.close();
    }
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });

  mainWindow.loadURL(config.appUrl).catch((err) => {
    dialog.showErrorBox(
      'تعذر فتح النظام',
      'تأكد أن الخادم يعمل وأن العنوان صحيح في config.json\n\n' +
        config.appUrl +
        '\n\n' +
        String(err && err.message ? err.message : err)
    );
  });
}

app.whenReady().then(() => {
  createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

app.on('before-quit', () => {
  allowQuit = true;
});
