<?php
/** @var array $_ */
?>
<div id="stammbaum-app" data-locale="<?php p($_['locale']); ?>" data-api-base="<?php p(
    $_['apiBase'],
); ?>" data-user-id="<?php p($_['userId']); ?>" data-request-token="<?php p($_['requesttoken'] ?? ''); ?>">
  <p role="status">Stammbaum …</p>
  <noscript>JavaScript is required to use Stammbaum.</noscript>
</div>
