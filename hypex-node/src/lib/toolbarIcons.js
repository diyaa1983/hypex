'use strict';

const { esc } = require('./html');

const BACK_ICON_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

const PRINT_ICON_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>';

function backAction(href, label = 'عودة') {
  return { icon: 'back', href, label, ghost: true };
}

function printAction(label = 'طباعة') {
  return { icon: 'print', label, primary: true, print: true };
}

function backLinkHtml(href, label = 'عودة', className = 'hx-doc-btn hx-doc-btn--icon') {
  return `<a class="${esc(className)}" href="${esc(href)}" aria-label="${esc(label)}" title="${esc(label)}">${BACK_ICON_SVG}</a>`;
}

function printBtnHtml(label = 'طباعة', className = 'hx-doc-btn hx-doc-btn--pri hx-doc-btn--icon', extra = '') {
  return `<button type="button" class="${esc(className)}" data-print="1" aria-label="${esc(label)}" title="${esc(label)}"${extra}>${PRINT_ICON_SVG}</button>`;
}

function siPrintBtnHtml(label = 'طباعة') {
  return `<button type="button" class="si-btn si-btn--primary si-btn--print si-btn--icon no-print" data-print="1" aria-label="${esc(label)}" title="${esc(label)}">${PRINT_ICON_SVG}</button>`;
}

function siBackLinkHtml(href, label = 'عودة') {
  return `<a class="si-btn si-btn--ghost si-btn--icon no-print" href="${esc(href)}" aria-label="${esc(label)}" title="${esc(label)}">${BACK_ICON_SVG}</a>`;
}

function heroActionHtml(a, cls) {
  const aria = esc(a.label || (a.icon === 'back' ? 'عودة' : 'طباعة'));
  if (a.icon === 'print') {
    return `<button type="button" class="${cls} si-btn--icon si-btn--print no-print" data-print="1" aria-label="${aria}" title="${aria}">${PRINT_ICON_SVG}</button>`;
  }
  if (a.icon === 'back') {
    const target = a.external ? ' target="_blank" rel="noopener"' : '';
    return `<a class="${cls} si-btn--icon no-print" href="${esc(a.href || '#')}" aria-label="${aria}" title="${aria}"${target}>${BACK_ICON_SVG}</a>`;
  }
  return '';
}

module.exports = {
  BACK_ICON_SVG,
  PRINT_ICON_SVG,
  backAction,
  printAction,
  backLinkHtml,
  printBtnHtml,
  siPrintBtnHtml,
  siBackLinkHtml,
  heroActionHtml,
};
