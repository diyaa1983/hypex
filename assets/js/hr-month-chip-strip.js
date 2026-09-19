(function (global) {
    'use strict';

    function syncActiveChips(form, month) {
        if (!form) return;
        form.querySelectorAll('.hr-mchip-chip').forEach(function (chip) {
            var m = parseInt(chip.getAttribute('data-month') || '0', 10);
            var active = m === month;
            chip.classList.toggle('is-active', active);
            chip.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    /**
     * @param {HTMLFormElement|null} form
     * @param {{monthInputId?: string, onSelect?: function(number, HTMLElement): void, autoSubmit?: boolean}} [options]
     */
    function bind(form, options) {
        options = options || {};
        if (!form) return null;

        var monthInputId = options.monthInputId || 'hr-mchip-filter-month';
        var monthInput = document.getElementById(monthInputId);
        if (!monthInput) return null;

        var onSelect = typeof options.onSelect === 'function' ? options.onSelect : null;
        var autoSubmit = options.autoSubmit !== false;

        form.querySelectorAll('.hr-mchip-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var m = parseInt(chip.getAttribute('data-month') || '0', 10);
                if (m < 1 || m > 12) return;
                monthInput.value = String(m);
                syncActiveChips(form, m);
                if (onSelect) {
                    onSelect(m, chip);
                    return;
                }
                if (!autoSubmit) return;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });

        return {
            syncActive: function (month) {
                syncActiveChips(form, month);
            },
        };
    }

    global.HrMonthChipStrip = {
        bind: bind,
        syncActive: syncActiveChips,
    };
})(typeof window !== 'undefined' ? window : self);
