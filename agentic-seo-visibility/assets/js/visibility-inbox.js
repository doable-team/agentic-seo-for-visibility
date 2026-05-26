/* global VisibilityInbox */
(function () {
  'use strict';

  if (typeof VisibilityInbox === 'undefined') return;

  var INBOX_POLL_MS = 30000;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function ajax(action, payload) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('_ajax_nonce', VisibilityInbox.nonce);
    Object.keys(payload || {}).forEach(function (k) {
      if (payload[k] !== null && payload[k] !== undefined) fd.append(k, payload[k]);
    });
    return fetch(VisibilityInbox.ajaxUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
    }).then(function (r) { return r.json(); });
  }

  function pillCls(kind, value) {
    if (kind === 'risk') {
      if (value === 'high') return 'visibility-pill visibility-pill-rose';
      if (value === 'medium') return 'visibility-pill visibility-pill-amber';
      return 'visibility-pill visibility-pill-emerald';
    }
    if (kind === 'status') {
      switch (value) {
        case 'pending':  return 'visibility-pill visibility-pill-amber';
        case 'approved': return 'visibility-pill visibility-pill-blue';
        case 'executed': return 'visibility-pill visibility-pill-emerald';
        case 'rejected': return 'visibility-pill visibility-pill-rose';
        case 'failed':   return 'visibility-pill visibility-pill-rose';
        case 'expired':  return 'visibility-pill visibility-pill-muted';
        default:         return 'visibility-pill visibility-pill-muted';
      }
    }
    return 'visibility-pill';
  }

  function fillCard(card, row) {
    card.dataset.id = row.id;
    var risk = card.querySelector('[data-role=risk]');
    risk.textContent = (row.preview && row.preview.riskTier ? row.preview.riskTier : 'low') + ' risk';
    risk.className = pillCls('risk', row.preview && row.preview.riskTier);

    var status = card.querySelector('[data-role=status]');
    status.textContent = row.status;
    status.className = pillCls('status', row.status);

    var auto = card.querySelector('[data-role=auto]');
    if (row.decision && row.decision.autoApproved) auto.hidden = false;

    var group = card.querySelector('[data-role=group]');
    group.textContent = row.actionType;

    card.querySelector('[data-role=title]').textContent = row.preview && row.preview.title ? row.preview.title : row.actionType;
    var sub = card.querySelector('[data-role=subtitle]');
    if (row.preview && row.preview.subtitle) sub.textContent = row.preview.subtitle; else sub.remove();

    var detail = card.querySelector('[data-role=detail]');
    if (row.preview && row.preview.detail) {
      detail.textContent = JSON.stringify(row.preview.detail, null, 2);
    } else {
      detail.remove();
    }

    // Wire actions
    var rejectForm = card.querySelector('.visibility-reject-form');
    card.querySelector('[data-action=approve]').addEventListener('click', function () {
      setBusy(card, true);
      ajax('visibility_inbox_approve', { requestId: row.id }).then(function (resp) {
        setBusy(card, false);
        if (!resp.success) return alert(resp.data && resp.data.message ? resp.data.message : 'Approve failed');
        card.remove();
        refresh();
      });
    });
    card.querySelector('[data-action=reject]').addEventListener('click', function () {
      rejectForm.hidden = false;
    });
    card.querySelector('[data-action=reject-cancel]').addEventListener('click', function () {
      rejectForm.hidden = true;
    });
    card.querySelector('[data-action=reject-confirm]').addEventListener('click', function () {
      var note = rejectForm.querySelector('textarea').value.trim();
      if (!note) return alert('A rejection note is required.');
      setBusy(card, true);
      ajax('visibility_inbox_reject', { requestId: row.id, note: note }).then(function (resp) {
        setBusy(card, false);
        if (!resp.success) return alert(resp.data && resp.data.message ? resp.data.message : 'Reject failed');
        card.remove();
        refresh();
      });
    });
  }

  function setBusy(card, busy) {
    $$('button', card).forEach(function (b) { b.disabled = busy; });
  }

  function renderRows(rows, listEl, emptyText) {
    if (!rows || !rows.length) {
      listEl.innerHTML = '';
      var p = document.createElement('p');
      p.className = 'visibility-empty';
      p.textContent = emptyText;
      listEl.appendChild(p);
      return;
    }
    listEl.innerHTML = '';
    var tpl = $('#visibility-inbox-card-template');
    rows.forEach(function (row) {
      if (!tpl) return;
      var clone = tpl.content.cloneNode(true);
      var card = clone.querySelector('.visibility-card');
      fillCard(card, row);
      listEl.appendChild(clone);
    });
  }

  var pollTimer = null;
  function startPolling(fn) {
    stopPolling();
    pollTimer = setInterval(function () {
      if (document.visibilityState === 'visible') fn();
    }, INBOX_POLL_MS);
  }
  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  var statusEl = null;
  function setStatus(msg) {
    if (!statusEl) statusEl = $('#visibility-inbox-status');
    if (statusEl) statusEl.textContent = msg || '';
  }

  function refresh() {
    var list = $('#visibility-inbox-list');
    if (!list) return;
    setStatus('Refreshing…');
    ajax('visibility_inbox_fetch').then(function (resp) {
      setStatus('Updated ' + new Date().toLocaleTimeString());
      if (!resp.success) {
        list.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'visibility-empty';
        p.textContent = resp.data && resp.data.message ? resp.data.message : 'Failed to load inbox.';
        list.appendChild(p);
        return;
      }
      var rows = (resp.data && resp.data.rows) || [];
      // Only show pending rows in the Inbox view.
      rows = rows.filter(function (r) { return r.status === 'pending'; });
      renderRows(rows, list, list.dataset.emptyText || 'No pending requests.');
      updateInboxCount(rows.length);
    });
  }

  function updateInboxCount(n) {
    var badge = $('#visibility-inbox-count');
    if (!badge) return;
    if (n > 0) {
      badge.textContent = String(n);
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  function refreshHistory() {
    var list = $('#visibility-activity-list');
    var filter = $('#visibility-activity-filter');
    var status = filter ? filter.value : 'any';
    if (!list) return;
    ajax('visibility_inbox_history', { status: status }).then(function (resp) {
      if (!resp.success) {
        list.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'visibility-empty';
        p.textContent = resp.data && resp.data.message ? resp.data.message : 'Failed to load activity.';
        list.appendChild(p);
        return;
      }
      var rows = (resp.data && resp.data.rows) || [];
      renderRows(rows, list, 'No activity yet.');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Approvals and Activity are separate admin pages; only one of these
    // roots exists per page load.
    if ($('#visibility-inbox-list')) {
      $('#visibility-inbox-refresh').addEventListener('click', refresh);
      refresh();
      startPolling(refresh);
    }
    if ($('#visibility-activity-list')) {
      $('#visibility-activity-refresh').addEventListener('click', refreshHistory);
      $('#visibility-activity-filter').addEventListener('change', refreshHistory);
      refreshHistory();
    }
  });
})();
