<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addScript('starrate', 'starrate-main');
\OCP\Util::addStyle('starrate', 'starrate-main');
?>
<div id="starrate-root"
     data-nc-url="<?= \OC::$server->getURLGenerator()->getBaseUrl() ?>"
     data-version="<?= htmlspecialchars($_['version'] ?? '') ?>"></div>
