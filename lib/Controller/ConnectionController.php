<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;

use OCA\Stammbaum\Service\{IntegrationConnections, Problem};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse, Response};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;

final class ConnectionController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private IntegrationConnections $connections,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    private function respond(callable $fn): Response
    {
        try {
            $user = $this->session->getUser();
            if (!$user || !$user->isEnabled()) {
                throw new Problem('unauthenticated', 'Sign in to continue.', 401);
            }
            $response = new JSONResponse($fn($user->getUID()));
        } catch (Problem $e) {
            $response = new JSONResponse(
                ['error' => $e->errorCode, 'message' => $e->getMessage()],
                $e->status,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Stammbaum connection reconciliation failed', [
                'app' => 'stammbaum',
                'exception' => $e,
            ]);
            $response = new JSONResponse(
                ['error' => 'connection_error', 'message' => 'Connections could not be loaded. Try again.'],
                500,
            );
        }
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $tree, int $page = 0): Response
    {
        return $this->respond(fn($actor) => $this->connections->list($tree, $actor, $page));
    }

    #[NoAdminRequired]
    public function refresh(string $tree, string $kind, string $id): Response
    {
        return $this->respond(fn($actor) => $this->connections->refresh($tree, $actor, $kind, $id));
    }

    #[NoAdminRequired]
    public function unlink(string $tree, string $kind, string $id): Response
    {
        return $this->respond(fn($actor) => $this->connections->unlink($tree, $actor, $kind, $id));
    }

    #[NoAdminRequired]
    public function acknowledgeEtag(string $tree, string $kind, string $id): Response
    {
        return $this->respond(
            fn($actor) => $this->connections->acknowledgeEtag(
                $tree,
                $actor,
                $kind,
                $id,
                $this->request->getParams(),
            ),
        );
    }

    #[NoAdminRequired]
    public function observeEtag(string $tree, string $kind, string $id): Response
    {
        return $this->respond(
            fn($actor) => $this->connections->observeEtag(
                $tree,
                $actor,
                $kind,
                $id,
                $this->request->getParams(),
            ),
        );
    }

    #[NoAdminRequired]
    public function reviewRemoval(string $tree, string $kind, string $id): Response
    {
        return $this->respond(fn($actor) => $this->connections->reviewRemoval($tree, $actor, $kind, $id));
    }

    #[NoAdminRequired]
    public function completeRemoval(string $tree, string $kind, string $id): Response
    {
        return $this->respond(
            fn($actor) => $this->connections->completeRemoval(
                $tree,
                $actor,
                $kind,
                $id,
                $this->request->getParams(),
            ),
        );
    }
}
