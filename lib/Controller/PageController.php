<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\AppFramework\Http\TemplateResponse;
use OCP\{IRequest, IURLGenerator, IUserSession, Util};
final class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urls,
        private IUserSession $session,
        private \OCA\Stammbaum\Service\Preferences $preferences,
    ) {
        parent::__construct($appName, $request);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $assets = json_decode(
            file_get_contents(__DIR__ . '/../../appinfo/assets.json'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        Util::addScript('stammbaum', $assets['name']);
        Util::addStyle('stammbaum', $assets['name']);
        return new TemplateResponse('stammbaum', 'main', [
            'apiBase' => substr($this->urls->linkToRoute('stammbaum.api.trees'), 0, -strlen('/trees')),
            'userId' => $this->session->getUser()?->getUID() ?? '',
            'locale' => $this->preferences->locale($this->session->getUser()->getUID())['locale'],
        ]);
    }
}
