<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;

use OCA\Stammbaum\Service\{Problem, ToolCredentials};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired, PasswordConfirmationRequired};
use OCP\AppFramework\Http\JSONResponse;
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;

final class CredentialController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private ToolCredentials $credentials,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return $this->respond(fn($actor) => ['items' => $this->credentials->list($actor)]);
    }

    #[NoAdminRequired]
    #[PasswordConfirmationRequired]
    public function issue(): JSONResponse
    {
        return $this->respond(
            fn($actor) => $this->credentials->issue($actor, [
                'label' => $this->request->getParam('label'),
                'scopes' => $this->request->getParam('scopes'),
                'treeIds' => $this->request->getParam('treeIds'),
                'expiresInDays' => $this->request->getParam('expiresInDays'),
                'requestId' => $this->request->getParam('requestId'),
            ]),
            201,
        );
    }

    #[NoAdminRequired]
    public function revoke(string $id): JSONResponse
    {
        return $this->respond(function ($actor) use ($id) {
            $this->credentials->revoke($actor, $id);
            return ['ok' => true];
        });
    }

    #[NoAdminRequired]
    public function revokeAll(): JSONResponse
    {
        return $this->respond(function ($actor) {
            $this->credentials->revokeAll($actor);
            return ['ok' => true];
        });
    }

    private function respond(callable $action, int $status = 200): JSONResponse
    {
        try {
            $user = $this->session->getUser();
            if (
                !$user ||
                !$user->isEnabled() ||
                $this->request->getHeader('Authorization') !== '' ||
                $this->request->getHeader('PHP_AUTH_USER') !== ''
            ) {
                throw new Problem('unauthenticated', 'Sign in to continue.', 401);
            }
            $response = new JSONResponse($action($user->getUID()), $status);
        } catch (Problem $problem) {
            $response = new JSONResponse(
                [
                    'error' => $problem->errorCode,
                    'message' => $problem->getMessage(),
                    'details' => $problem->details,
                ],
                $problem->status,
            );
            if ($problem->status === 429) {
                $response->addHeader('Retry-After', (string) $problem->details['retryAfter']);
            }
        } catch (\Throwable $error) {
            $this->logger->error('Stammbaum credential management failed', [
                'app' => 'stammbaum',
                'type' => $error::class,
            ]);
            $response = new JSONResponse(['error' => 'server_error'], 500);
        }
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }
}
