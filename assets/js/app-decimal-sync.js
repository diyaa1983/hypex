(function (global) {

  'use strict';



  /** ربط حقول الخانات العشرية في الإعدادات العامة بتحديث فوري للشاشات المفتوحة. */

  function bindSettingsDecimalField() {

    if (!global.AppFormat) return;



    var systemInp = document.querySelector('input[name="decimal_places"]');

    if (systemInp && typeof AppFormat.setDecimalPlaces === 'function') {

      function applySystem() {

        AppFormat.setDecimalPlaces(systemInp.value, { persist: true });

      }

      systemInp.addEventListener('input', applySystem);

      systemInp.addEventListener('change', applySystem);

    }



    var unitInp = document.querySelector('input[name="invoice_unit_price_decimal_places"]');

    if (unitInp && typeof AppFormat.setInvoiceUnitPriceDecimals === 'function') {

      function applyUnit() {

        AppFormat.setInvoiceUnitPriceDecimals(unitInp.value, { persist: true });

      }

      unitInp.addEventListener('input', applyUnit);

      unitInp.addEventListener('change', applyUnit);

    }

  }



  if (document.readyState === 'loading') {

    document.addEventListener('DOMContentLoaded', bindSettingsDecimalField);

  } else {

    bindSettingsDecimalField();

  }

})(typeof window !== 'undefined' ? window : this);

