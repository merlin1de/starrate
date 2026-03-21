<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addScript('starrate', 'starrate-guest');
\OCP\Util::addStyle('starrate', 'starrate-main');

$token    = $_['token']     ?? '';
$canRate  = $_['can_rate']  ?? false;
$minRating = $_['min_rating'] ?? 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $l->t('Fotogalerie') ?> – StarRate</title>
  <link rel="stylesheet" href="<?= \OC::$server->getURLGenerator()->linkTo('starrate', 'css/starrate-main.css') ?>">
</head>
<body class="sr-guest-body">
  <div
    id="starrate-guest-root"
    data-token="<?= htmlspecialchars($token, ENT_QUOTES) ?>"
    data-can-rate="<?= $canRate ? 'true' : 'false' ?>"
    data-min-rating="<?= (int) $minRating ?>"
  ></div>
  <script src="<?= \OC::$server->getURLGenerator()->linkTo('starrate', 'js/starrate-guest.js') ?>"></script>
</body>
</html>
