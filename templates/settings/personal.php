<?php
/** @var array $_ */
$settings = $_['settings'] ?? [];
?>
<div id="starrate-settings" class="section" data-settings='<?= json_encode($settings) ?>'>
  <h2 class="inlineblock">StarRate</h2>
  <!-- Einstellungen werden durch das Vue-Bundle gerendert -->
</div>
