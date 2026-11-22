export function credentialAuthority(php, secret, treeId, scope) {
  const code = `
    define('OC_CONSOLE', true);
    require '/var/www/html/lib/base.php';
    $input = json_decode(file_get_contents('php://stdin'), true, 32, JSON_THROW_ON_ERROR);
    $app = new \\OCA\\Stammbaum\\AppInfo\\Application();
    $auth = $app->getContainer()->get(\\OCA\\Stammbaum\\Service\\ToolCredentials::class);
    try {
      $result = ['status' => 200] + $auth->authorize($input['secret'], $input['treeId'], $input['scope']);
    } catch (\\OCA\\Stammbaum\\Service\\Problem $problem) {
      $result = ['status' => $problem->status, 'error' => $problem->errorCode];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
  `;
  return JSON.parse(php(['-r', code], JSON.stringify({ secret, treeId, scope })));
}
