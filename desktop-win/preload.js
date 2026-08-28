/**
 * Preload — تعريف بيئة غلاف ويندوز للتطبيق (بدون شريط متصفح).
 */
'use strict';

const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('hypexDesktop', {
  isElectron: true,
  shell: 'electron',
});
