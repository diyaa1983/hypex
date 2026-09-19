(function () {
  'use strict';

  var cfg = window.MUserGpsList || {};
  var tableWrap = document.getElementById('m-user-gps-table-wrap');
  var tbody = document.getElementById('m-user-gps-tbody');
  var searchInp = document.getElementById('m-user-gps-search');
  var fromInp = document.getElementById('m-user-gps-from');
  var toInp = document.getElementById('m-user-gps-to');
  var showBtn = document.getElementById('m-user-gps-show');
  var pendingEl = document.getElementById('m-user-gps-pending');
  var loadingEl = document.getElementById('m-user-gps-loading');
  var emptyEl = document.getElementById('m-user-gps-empty');

  if (!tbody) return;

  var gpsIconSvg =
    '<svg class="sal-gps-icon-btn__svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">' +
    '<path fill="#fff" d="M12 2.5a6.25 6.25 0 0 0-6.25 6.25c0 4.69 6.25 12.75 6.25 12.75S18.25 13.44 18.25 8.75A6.25 6.25 0 0 0 12 2.5Zm0 8.5a2.25 2.25 0 1 1 0-4.5 2.25 2.25 0 0 1 0 4.5Z"/>' +
    '</svg>';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function mapCellHtml(row) {
    return (
      '<button type="button" class="sal-gps-icon-btn sal-gps-map-open"' +
      ' data-lat="' +
      esc(row.latitude != null ? row.latitude : '') +
      '"' +
      ' data-lng="' +
      esc(row.longitude != null ? row.longitude : '') +
      '"' +
      ' data-customer="' +
      esc(row.user_label || '') +
      '"' +
      ' data-place="' +
      esc(row.place_label || row.gps_place || '') +
      '"' +
      ' data-landmark="' +
      esc(row.landmark_label || row.gps_landmark || '') +
      '"' +
      ' data-embed="' +
      esc(row.map_embed_url || '') +
      '"' +
      ' data-external="' +
      esc(row.map_url || '') +
      '"' +
      ' title="عرض الخريطة">' +
      '<span class="sal-gps-icon-btn__glyph">' +
      gpsIconSvg +
      '</span></button>'
    );
  }

  function loadRows() {
    var q = searchInp ? searchInp.value.trim() : '';
    if (pendingEl) pendingEl.hidden = true;
    if (loadingEl) loadingEl.hidden = false;
    if (emptyEl) emptyEl.hidden = true;
    if (tableWrap) tableWrap.hidden = true;
    tbody.innerHTML = '';

    var params = ['show=1', 'q=' + encodeURIComponent(q)];
    if (fromInp && fromInp.value.trim()) {
      params.push('date_from=' + encodeURIComponent(fromInp.value.trim()));
    }
    if (toInp && toInp.value.trim()) {
      params.push('date_to=' + encodeURIComponent(toInp.value.trim()));
    }
    var url = (cfg.listApi || '') + (cfg.listApi.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (loadingEl) loadingEl.hidden = true;
        if (!data || !data.ok) {
          if (window.AppDialog && AppDialog.error) {
            AppDialog.error((data && data.message) || 'تعذر تحميل المواقع.');
          }
          return;
        }
        var rows = data.rows || [];
        if (rows.length === 0) {
          if (emptyEl) emptyEl.hidden = false;
          return;
        }
        rows.forEach(function (row) {
          var srcClass =
            row.source_badge_class ||
            (row.gps_source === 'mobile' ? 'sal-gps-src--mobile' : 'sal-gps-src--desktop');
          var srcLabel = row.source_label || (row.gps_source === 'mobile' ? 'هاتف' : 'Windows');
          var tr = document.createElement('tr');
          var placeHtml = row.place_label || row.gps_place
            ? '<span class="sal-gps-place-label">' + esc(row.place_label || row.gps_place) + '</span>'
            : '<span class="muted sal-gps-place-pending">يُحدَّد تلقائياً</span>';
          var landmarkHtml = row.landmark_label || row.gps_landmark
            ? '<span class="sal-gps-landmark-label">' + esc(row.landmark_label || row.gps_landmark) + '</span>'
            : '';
          tr.innerHTML =
            '<td class="m-inv-gps-td-map">' +
            mapCellHtml(row) +
            '</td>' +
            '<td class="m-inv-gps-place">' +
            placeHtml +
            landmarkHtml +
            '</td>' +
            '<td class="m-inv-gps-user">' +
            '<span class="sal-gps-user-label">' +
            esc(row.user_label || '') +
            '</span>' +
            (row.username ? '<span class="sal-gps-user-sub">' + esc(row.username) + '</span>' : '') +
            '</td>' +
            '<td><span class="sal-gps-src ' +
            esc(srcClass) +
            '">' +
            esc(srcLabel) +
            '</span></td>' +
            '<td class="m-inv-gps-date">' +
            esc(row.captured_at_dmy || '') +
            (row.accuracy_label ? '<br><span class="muted m-inv-gps-time">±' + esc(row.accuracy_label) + '</span>' : '') +
            '</td>';
          tbody.appendChild(tr);
        });
        if (tableWrap) tableWrap.hidden = false;
      })
      .catch(function () {
        if (loadingEl) loadingEl.hidden = true;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الاتصال بالخادم.');
      });
  }

  if (showBtn) {
    showBtn.addEventListener('click', loadRows);
  }
})();
