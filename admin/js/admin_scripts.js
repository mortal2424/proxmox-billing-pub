/* admin/js/admin_scripts.js */
(function () {
  'use strict';

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function notify(msg) { alert(msg); }

  async function openVNC_admin_legacy(nodeId, vmId) {
    try {
      if (!nodeId || !vmId) throw new Error('nodeId и vmId обязательны');

      // Простой режим без AJAX: откроем редиректную страницу
      // window.open(`/templates/vnc_console.php?node_id=${encodeURIComponent(nodeId)}&vmid=${encodeURIComponent(vmId)}&redirect=1`, '_blank', 'noopener,noreferrer');
      // return;

      // AJAX-режим
      const url = `/templates/vnc_console.php?node_id=${encodeURIComponent(nodeId)}&vmid=${encodeURIComponent(vmId)}`;
      const res = await fetch(url, { credentials: 'same-origin' });
      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data || !data.success) {
        const msg = (data && data.error) ? data.error : (`HTTP ${res.status}`);
        throw new Error(msg);
      }
      window.open(data.data.url, '_blank', 'noopener,noreferrer');

    } catch (e) {
      console.error('VNC Error:', e);
      notify('VNC Error: ' + e.message);
    }
  }

// (disabled) window.openVNC is handled by proxmox.js

  document.addEventListener('DOMContentLoaded', function () {
    qsa('.btn-vnc[data-node][data-vmid]').forEach(function (btn) {
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        const nodeId = btn.getAttribute('data-node');
        const vmId   = btn.getAttribute('data-vmid') || btn.getAttribute('data-vm_id');
        // handled by proxmox.js: openVNC(nodeId, vmId);
      });
    });
  });

})();