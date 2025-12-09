/* ======================================================================
 * ITSP Proxmox helpers — VNC launcher (single-window, no duplicates)
 * File: admin/js/proxmox.js
 * ====================================================================== */

(function () {
  // Не подключать обработчики повторно
  if (window.__ITSP_PROXMOX_JS_LOADED__) return;
  window.__ITSP_PROXMOX_JS_LOADED__ = true;

  // Глобальный троттлинг, чтобы исключить множественные открытия вкладок
  let lastOpenAt = 0; // ms epoch

  /**
   * Собираем URL на серверный endpoint, который:
   *  - получает тикет
   *  - устанавливает cookie
   *  - делает redirect на PVE
   * Поэтому всегда используем redirect=1.
   */
  function buildVncUrl(nodeId, vmId) {
    const q = new URLSearchParams({
      node_id: String(nodeId),
      vmid: String(vmId),
      redirect: '1',
    });
    return `/templates/vnc_console.php?${q.toString()}`;
  }

  /**
   * Открывает VNC. Защита от дублей по времени и по кнопке.
   * @param {string|number} nodeId
   * @param {string|number} vmId
   * @param {HTMLElement=} sourceEl  — кнопка/ссылка, если вызов пришёл из клика
   */
  function openVNC(nodeId, vmId, sourceEl) {
    if (!nodeId || !vmId) {
      alert('VNC Error: nodeId и vmId обязательны');
      return;
    }

    // Глобальный анти-дубль: если два обработчика сработали почти одновременно
    const now = Date.now();
    if (now - lastOpenAt < 600) return; // игнорируем повторы в течение 600ms
    lastOpenAt = now;

    // Локальный анти-дубль: если у кнопки уже идёт открытие — не повторяем
    if (sourceEl) {
      if (sourceEl.dataset.opening === '1') return;
      sourceEl.dataset.opening = '1';
      // снимаем блокировку через небольшой интервал
      setTimeout(() => {
        try { delete sourceEl.dataset.opening; } catch (_) {}
      }, 1500);
    }

    // Открываем РОВНО одно окно. Сервер сам сделает всё остальное.
    const url = buildVncUrl(nodeId, vmId);
    window.open(url, '_blank', 'noopener,noreferrer');
  }

  /**
   * Экспорт функции для совместимости со старым inline `onclick="openVNC(...)"`
   * Здесь тоже действует глобальный анти-дубль по времени.
   */
  window.openVNC = function (nodeId, vmId) {
    openVNC(nodeId, vmId, /* sourceEl */ null);
  };

  /**
   * Делегирование кликов по кнопкам `.btn-vnc`
   * Требуемая разметка:
   *   <a href="#"
   *      class="btn-vnc"
   *      data-node="7"
   *      data-vmid="103">
   *     <i class="fas fa-desktop"></i>
   *   </a>
   */
  document.addEventListener(
    'click',
    function (ev) {
      const btn = ev.target.closest('.btn-vnc');
      if (!btn) return;

      // Если другой обработчик уже забрал событие — выходим
      if (ev.defaultPrevented) return;

      // Предохраняемся от двойной навигации
      ev.preventDefault();
      ev.stopPropagation();

      const nodeId = btn.getAttribute('data-node');
      const vmId = btn.getAttribute('data-vmid');

      // Если по ошибке нет атрибутов — сообщаем и выходим
      if (!nodeId || !vmId) {
        alert('VNC Error: nodeId и vmId обязательны');
        return;
      }

      openVNC(nodeId, vmId, btn);
    },
    // capture=false — достаточно обычной фазы
    false
  );

  /**
   * Дополнительно: перехватываем прямые ссылки на vnc_console.php,
   * если где-то осталась старая разметка. Это предотвратит второе окно.
   */
  document.addEventListener('click', function (ev) {
    const a = ev.target.closest('a[href*="vnc_console.php"]');
    if (!a) return;

    // Если это уже .btn-vnc — первый обработчик все сделает
    if (a.classList.contains('btn-vnc')) return;

    // Иначе перехватим и направим через единый механизм
    const url = new URL(a.href, window.location.origin);
    const nodeId = url.searchParams.get('node_id') || url.searchParams.get('node') || a.dataset.node;
    const vmId = url.searchParams.get('vmid') || url.searchParams.get('vm_id') || a.dataset.vmid;

    if (nodeId && vmId) {
      ev.preventDefault();
      ev.stopPropagation();
      openVNC(nodeId, vmId, a);
    }
  });

  // Готово.
})();