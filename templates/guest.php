<?php
/** @var array $_ */
\OCP\Util::addScript('starrate', 'starrate-guest');
\OCP\Util::addStyle('starrate', 'starrate-guest');

$token     = $_['token']      ?? '';
$canRate   = $_['can_rate']   ?? false;
$guestName = $_['guest_name'] ?? '';
?>
<div
  id="starrate-guest-root"
  data-token="<?= htmlspecialchars($token, ENT_QUOTES) ?>"
  data-can-rate="<?= $canRate ? 'true' : 'false' ?>"
  data-guest-name="<?= htmlspecialchars($guestName, ENT_QUOTES) ?>"
></div>
