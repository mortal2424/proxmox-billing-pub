<?php
if (!isset($googleLoginAvailable, $telegramLoginAvailable)) return;
?>
<style>
.panel-social-login{display:flex;justify-content:center;align-items:center;gap:.5rem;margin:.65rem 0}
.panel-social-login>.panel-social-icon{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;padding:0;margin:0;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#1688bd;cursor:pointer}
.panel-social-login>.panel-social-icon:focus-visible{outline:2px solid #1675bd;outline-offset:2px}
.panel-social-icon svg{width:22px;height:22px}
</style>
<div class="panel-social-login" aria-label="Вход через другие сервисы">
<?php if ($telegramLoginAvailable): ?>
<?php if (isset($telegramLoginUrl)): ?>
<a class="panel-social-icon" href="<?= htmlspecialchars($telegramLoginUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Войти через Telegram" title="Войти через Telegram">
<?php else: ?>
<button type="button" class="panel-social-icon" id="panelTelegramToggle" aria-expanded="false" aria-controls="panelTelegramWidget" aria-label="Войти через Telegram" title="Войти через Telegram">
<?php endif; ?>
<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.7 3.4 2.9 10.6c-1.3.5-1.3 1.3-.2 1.7l4.8 1.5 1.8 5.5c.2.6.1.8.8.8.5 0 .8-.2 1-.4l2.3-2.3 4.8 3.5c.9.5 1.5.2 1.7-.8l3.1-15.5c.3-1.2-.5-1.8-1.3-1.2ZM9.4 13.5l9.4-5.9c.5-.3 1-.1.6.2l-7.8 7.1-.3 3.4-1.9-4.8Z"/></svg>
<?php if (isset($telegramLoginUrl)): ?></a><?php else: ?></button><?php endif; ?>
<?php endif; ?>
<?php if ($googleLoginAvailable): ?>
<a class="panel-social-icon" href="google.php?start=1" aria-label="Войти через Google" title="Войти через Google">
<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#4285F4" d="M43.6 24.5c0-1.4-.1-2.8-.4-4.1H24v7.8h11a9.4 9.4 0 0 1-4.1 6.1v5h6.6c3.9-3.6 6.1-8.8 6.1-14.8Z"/><path fill="#34A853" d="M24 44c5.5 0 10.1-1.8 13.5-4.8l-6.6-5a12.3 12.3 0 0 1-18.3-6.5H5.8v5.2A20 20 0 0 0 24 44Z"/><path fill="#FBBC05" d="M12.6 27.7a12 12 0 0 1 0-7.4v-5.2H5.8a20 20 0 0 0 0 17.8l6.8-5.2Z"/><path fill="#EA4335" d="M24 11.9c3 0 5.7 1 7.8 3l5.8-5.8A19.2 19.2 0 0 0 24 4 20 20 0 0 0 5.8 15.1l6.8 5.2A12 12 0 0 1 24 11.9Z"/></svg>
</a>
<?php endif; ?>
</div>
<?php if ($telegramLoginAvailable && !isset($telegramLoginUrl)): ?>
<script>
document.getElementById('panelTelegramToggle').addEventListener('click', function () {
    const widget = document.getElementById('panelTelegramWidget');
    widget.hidden = !widget.hidden;
    this.setAttribute('aria-expanded', String(!widget.hidden));
});
</script>
<?php endif ?>
