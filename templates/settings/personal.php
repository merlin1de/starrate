<?php
/** @var array $_ */
\OCP\Util::addScript('starrate', 'starrate-settings');
$settings = $_['settings'] ?? [];
?>
<div id="starrate-settings" class="section" data-settings='<?= json_encode($settings) ?>'></div>
