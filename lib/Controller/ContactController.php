<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;
use OCA\Stammbaum\Service\{ContactLinks, Problem};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse, Response};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;

final class ContactController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private ContactLinks $links,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    private function respond(callable $fn, int $status = 200): Response
    {
        try {
            $u = $this->session->getUser();
            if (!$u || !$u->isEnabled()) {
                throw new Problem('unauthenticated', 'Sign in to continue.', 401);
            }
            $r = new JSONResponse($fn($u->getUID()), $status);
        } catch (Problem $e) {
            $r = new JSONResponse(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        } catch (\Throwable $e) {
            $this->logger->error('Stammbaum contact connection failed', [
                'app' => 'stammbaum',
                'exception' => $e,
            ]);
            $r = new JSONResponse(
                [
                    'error' => 'contact_error',
                    'message' => 'This contact could not be processed. Refresh and try again.',
                ],
                500,
            );
        }
        $r->addHeader('Cache-Control', 'private, no-store');
        $r->addHeader('X-Content-Type-Options', 'nosniff');
        return $r;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function books(string $tree, string $person): Response
    {
        return $this->respond(fn($actor) => $this->links->books($tree, $actor, $person));
    }
    #[NoAdminRequired]
    public function search(string $tree): Response
    {
        $b = $this->request->getParams();
        return $this->respond(fn($actor) => $this->links->search($tree, $actor, $b));
    }
    #[NoAdminRequired]
    public function reviewLink(string $tree): Response
    {
        $b = $this->request->getParams();
        return $this->respond(fn($actor) => $this->links->reviewLink($tree, $actor, $b));
    }
    #[NoAdminRequired]
    public function link(string $tree): Response
    {
        $b = $this->request->getParams();
        return $this->respond(fn($actor) => $this->links->link($tree, $actor, $b), 201);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $tree, string $person, int $page = 0): Response
    {
        return $this->respond(fn($actor) => $this->links->links($tree, $actor, $person, $page));
    }
    #[NoAdminRequired]
    public function reviewCopy(string $tree, string $id): Response
    {
        $b = $this->request->getParams();
        return $this->respond(fn($actor) => $this->links->reviewCopy($tree, $actor, $id, $b));
    }
    #[NoAdminRequired]
    public function copy(string $tree, string $id): Response
    {
        $b = $this->request->getParams();
        return $this->respond(fn($actor) => $this->links->copy($tree, $actor, $id, $b), 201);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function birthday(string $tree, string $person): Response
    {
        return $this->respond(fn($actor) => $this->links->birthdaySignal($tree, $actor, $person));
    }
    #[NoAdminRequired]
    public function unlink(string $tree, string $id): Response
    {
        return $this->respond(fn($actor) => $this->links->unlink($tree, $actor, $id));
    }
}
