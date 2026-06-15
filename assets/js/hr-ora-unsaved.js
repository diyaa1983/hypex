/** @deprecated استخدم ScreenExitGuard — هذا الملف للتوافق مع الشاشات القديمة فقط */
(function (global) {
    'use strict';
    if (global.ScreenExitGuard && !global.HrOraUnsaved) {
        global.HrOraUnsaved = { bind: global.ScreenExitGuard.bind };
    }
})(typeof window !== 'undefined' ? window : globalThis);
