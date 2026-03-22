<?php ?>
<style>
body { background: #1a1a2e; color: #d4d4d8; font-family: 'Inter', system-ui, sans-serif; }
*, *::before, *::after { box-sizing: border-box; }
.exp-wrap {
  display: flex; align-items: center; justify-content: center;
  min-height: 80vh; padding: 1rem;
}
.exp-box {
  background: #16213e; border: 1px solid #2a2a3e; border-radius: 12px;
  padding: 2rem; width: min(400px, 100%); text-align: center;
  display: flex; flex-direction: column; gap: 0.75rem; align-items: center;
}
.exp-icon { font-size: 2.5rem; }
.exp-box h1 { color: #fff; font-size: 1.1rem; font-weight: 600; margin: 0; }
.exp-box p  { color: #a1a1aa; font-size: 0.875rem; margin: 0; }
</style>

<div class="exp-wrap">
  <div class="exp-box">
    <div class="exp-icon">🔗</div>
    <h1>Dieser Link ist nicht mehr gültig</h1>
    <p>Der Freigabe-Link ist abgelaufen oder wurde deaktiviert.</p>
  </div>
</div>
