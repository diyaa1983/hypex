(function (global) {

  'use strict';



  var modal = null;

  var iframe = null;

  var titleEl = null;

  var placeEl = null;

  var landmarkEl = null;

  var metaEl = null;

  var externalEl = null;

  var geocodeApi = '';



  function qs(id) {

    return document.getElementById(id);

  }



  function readConfig() {

    var cfg = global.SalInvoiceGpsMapConfig || {};

    geocodeApi = String(cfg.geocodeApi || '');

  }



  function escHtml(s) {

    return String(s == null ? '' : s)

      .replace(/&/g, '&amp;')

      .replace(/</g, '&lt;')

      .replace(/>/g, '&gt;')

      .replace(/"/g, '&quot;');

  }



  function renderLocationCellHtml(place, landmark) {

    var html = '';

    if (place) {

      html += '<span class="sal-gps-place-label">' + escHtml(place) + '</span>';

    }

    if (landmark) {

      html += '<span class="sal-gps-landmark-label">' + escHtml(landmark) + '</span>';

    }

    if (!html) {

      html = '<span class="muted sal-gps-place-pending">يُحدَّد عند فتح الخريطة</span>';

    }

    return html;

  }



  function ensureModal() {

    if (modal) return;

    readConfig();

    modal = qs('sal-gps-map-modal');

    iframe = qs('sal-gps-map-iframe');

    titleEl = qs('sal-gps-map-title');

    placeEl = qs('sal-gps-map-place');

    landmarkEl = qs('sal-gps-map-landmark');

    metaEl = qs('sal-gps-map-meta');

    externalEl = qs('sal-gps-map-external');

    if (!modal) return;



    var close = function () {

      modal.hidden = true;

      modal.setAttribute('aria-hidden', 'true');

      document.body.classList.remove('sal-gps-map-modal-open');

      if (iframe) iframe.removeAttribute('src');

      if (placeEl) {

        placeEl.hidden = true;

        placeEl.textContent = '';

        placeEl.classList.remove('is-loading');

      }

      if (landmarkEl) {

        landmarkEl.hidden = true;

        landmarkEl.textContent = '';

        landmarkEl.classList.remove('is-loading');

      }

    };



    modal.querySelectorAll('[data-sal-gps-map-close]').forEach(function (el) {

      el.addEventListener('click', close);

    });



    document.addEventListener('keydown', function (e) {

      if (e.key === 'Escape' && modal && !modal.hidden) close();

    });

  }



  function setPlaceText(text, loading) {

    if (!placeEl) return;

    if (!text && !loading) {

      placeEl.hidden = true;

      placeEl.textContent = '';

      placeEl.classList.remove('is-loading');

      return;

    }

    placeEl.hidden = false;

    placeEl.classList.toggle('is-loading', !!loading);

    placeEl.textContent = text;

  }



  function setLandmarkText(text, loading) {

    if (!landmarkEl) return;

    if (!text && !loading) {

      landmarkEl.hidden = true;

      landmarkEl.textContent = '';

      landmarkEl.classList.remove('is-loading');

      return;

    }

    landmarkEl.hidden = false;

    landmarkEl.classList.toggle('is-loading', !!loading);

    landmarkEl.textContent = text;

  }



  function fetchLocation(opts) {

    var cachedPlace = String(opts.place || '').trim();

    var cachedLandmark = String(opts.landmark || '').trim();



    if (cachedPlace !== '') {

      setPlaceText('📍 ' + cachedPlace, false);

    } else {

      setPlaceText('جاري تحديد اسم المنطقة…', true);

    }



    if (cachedLandmark !== '') {

      setLandmarkText('🏛 أقرب معلم: ' + cachedLandmark, false);

    } else if (cachedPlace !== '') {

      setLandmarkText('جاري البحث عن أقرب معلم…', true);

    } else {

      setLandmarkText('', false);

    }



    if (cachedPlace !== '' && cachedLandmark !== '') {

      return Promise.resolve({ place: cachedPlace, landmark: cachedLandmark });

    }



    var url = '';

    if (geocodeApi) {

      if (opts.invoiceId && parseInt(opts.invoiceId, 10) > 0) {

        url =

          geocodeApi +

          (geocodeApi.indexOf('?') >= 0 ? '&' : '?') +

          'invoice_id=' +

          encodeURIComponent(String(opts.invoiceId));

      } else {

        url =

          geocodeApi +

          (geocodeApi.indexOf('?') >= 0 ? '&' : '?') +

          'lat=' +

          encodeURIComponent(String(opts.lat)) +

          '&lng=' +

          encodeURIComponent(String(opts.lng));

      }

    }



    if (!url) {

      setPlaceText('', false);

      setLandmarkText('', false);

      return Promise.resolve({ place: '', landmark: '' });

    }



    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })

      .then(function (r) {

        return r.json();

      })

      .then(function (data) {

        var place = data && data.ok && data.place ? String(data.place).trim() : '';

        var landmark = data && data.ok && data.landmark ? String(data.landmark).trim() : '';



        if (place !== '') {

          setPlaceText('📍 ' + place, false);

        } else {

          setPlaceText('تعذر تحديد اسم المنطقة لهذه الإحداثيات.', false);

        }



        if (landmark !== '') {

          setLandmarkText('🏛 أقرب معلم: ' + landmark, false);

        } else {

          setLandmarkText('لم يُعثر على معلم قريب ضمن نطاق البحث.', false);

        }



        if (opts.triggerEl) {

          if (place !== '') opts.triggerEl.setAttribute('data-place', place);

          if (landmark !== '') opts.triggerEl.setAttribute('data-landmark', landmark);

          var row = opts.triggerEl.closest('tr');

          if (row) {

            var placeCell = row.querySelector('.col-place, .m-inv-gps-place');

            if (placeCell) {

              placeCell.innerHTML = renderLocationCellHtml(place, landmark);

            }

          }

        }



        return { place: place, landmark: landmark };

      })

      .catch(function () {

        setPlaceText('تعذر الاتصال لتحديد الموقع.', false);

        setLandmarkText('', false);

        return { place: '', landmark: '' };

      });

  }



  function openMapModal(opts) {

    ensureModal();

    if (!modal || !iframe) return;



    var lat = parseFloat(opts.lat);

    var lng = parseFloat(opts.lng);

    if (!isFinite(lat) || !isFinite(lng)) return;



    var invoiceNo = String(opts.invoiceNo || '').trim();

    var customer = String(opts.customer || '').trim();

    var embedUrl = String(opts.embedUrl || '').trim();

    var externalUrl = String(opts.externalUrl || '').trim();



    if (!embedUrl) {

      var delta = 0.006;

      var bbox = [lng - delta, lat - delta, lng + delta, lat + delta].join(',');

      embedUrl =

        'https://www.openstreetmap.org/export/embed.html?bbox=' +

        encodeURIComponent(bbox) +

        '&layer=mapnik&marker=' +

        encodeURIComponent(lat.toFixed(6) + ',' + lng.toFixed(6));

    }



    if (titleEl) {

      titleEl.textContent = invoiceNo !== '' ? 'موقع فاتورة ' + invoiceNo : 'موقع الفاتورة';

    }

    if (metaEl) {

      var parts = [];

      if (customer !== '') parts.push(customer);

      parts.push(lat.toFixed(6) + ' ، ' + lng.toFixed(6));

      metaEl.textContent = parts.join(' — ');

    }

    if (externalEl) {

      externalEl.href = externalUrl || 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng);

    }



    iframe.src = embedUrl;

    modal.hidden = false;

    modal.setAttribute('aria-hidden', 'false');

    document.body.classList.add('sal-gps-map-modal-open');



    fetchLocation({

      lat: lat,

      lng: lng,

      invoiceId: opts.invoiceId || '',

      place: opts.place || '',

      landmark: opts.landmark || '',

      triggerEl: opts.triggerEl || null,

    });

  }



  function onMapTriggerClick(e) {

    var btn = e.target.closest('.sal-gps-map-open');

    if (!btn) return;

    e.preventDefault();

    openMapModal({

      lat: btn.getAttribute('data-lat'),

      lng: btn.getAttribute('data-lng'),

      invoiceId: btn.getAttribute('data-invoice-id') || '',

      invoiceNo: btn.getAttribute('data-invoice') || '',

      customer: btn.getAttribute('data-customer') || '',

      place: btn.getAttribute('data-place') || '',

      landmark: btn.getAttribute('data-landmark') || '',

      embedUrl: btn.getAttribute('data-embed') || '',

      externalUrl: btn.getAttribute('data-external') || '',

      triggerEl: btn,

    });

  }



  function init() {

    ensureModal();

    document.addEventListener('click', onMapTriggerClick);

  }



  global.SalInvoiceGpsMap = {

    open: openMapModal,

    init: init,

  };



  if (document.readyState === 'loading') {

    document.addEventListener('DOMContentLoaded', init);

  } else {

    init();

  }

})(typeof window !== 'undefined' ? window : this);

