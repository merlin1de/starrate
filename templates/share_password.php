<?php
/** @var array $_ */
$token = $_['token'] ?? '';
?>
<style>
body { background: #1a1a2e; color: #d4d4d8; font-family: 'Inter', system-ui, sans-serif; }
*, *::before, *::after { box-sizing: border-box; }
.pw-wrap {
  display: flex; align-items: center; justify-content: center;
  min-height: 80vh; padding: 1rem;
}
.pw-box {
  background: #16213e; border: 1px solid #2a2a3e; border-radius: 12px;
  padding: 2rem; width: min(400px, 100%); display: flex; flex-direction: column; gap: 1rem;
}
.pw-box h1 { color: #fff; font-size: 1.1rem; font-weight: 600; margin: 0; }
.pw-box p  { color: #a1a1aa; font-size: 0.875rem; margin: 0; }
.pw-box input {
  background: #0f0f1a; border: 1px solid #3f3f5a; border-radius: 6px;
  color: #d4d4d8; font-size: 0.9rem; padding: 0.5rem 0.75rem; width: 100%;
}
.pw-box input:focus { outline: none; border-color: #e94560; }
.pw-error { color: #e94560; font-size: 0.8rem; display: none; }
.pw-actions { display: flex; justify-content: flex-end; }
.pw-btn {
  background: #e94560; border: none; border-radius: 6px; color: #fff;
  cursor: pointer; font-size: 0.9rem; padding: 0.5rem 1.5rem;
}
.pw-btn:disabled { opacity: 0.4; cursor: not-allowed; }
</style>

<div class="pw-wrap">
  <div class="pw-box">
    <h1>Passwortgeschützte Galerie</h1>
    <p>Bitte gib das Passwort ein, um die Galerie zu öffnen.</p>
    <input type="password" id="sr-pw-input" placeholder="Passwort" />
    <span class="pw-error" id="sr-pw-err">Falsches Passwort</span>
    <div class="pw-actions">
      <button class="pw-btn" id="sr-pw-btn">Bestätigen</button>
    </div>
  </div>
</div>

<script>
(function() {
  var token = <?= json_encode($token) ?>;
  var input = document.getElementById('sr-pw-input');
  var btn   = document.getElementById('sr-pw-btn');
  var err   = document.getElementById('sr-pw-err');

  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') doVerify();
  });
  btn.addEventListener('click', doVerify);

  function doVerify() {
    var pw = input.value;
    if (!pw) return;
    btn.disabled = true;
    err.style.display = 'none';
    fetch('/index.php/apps/starrate/api/guest/' + token + '/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: pw }),
      credentials: 'same-origin',
    })
    .then(function(res) {
      if (res.ok) {
        window.location.reload();
      } else {
        err.style.display = 'block';
        btn.disabled = false;
      }
    })
    .catch(function() {
      err.style.display = 'block';
      btn.disabled = false;
    });
  }
})();
</script>
