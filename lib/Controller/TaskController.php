<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;
use OCA\Stammbaum\Service\{Problem, ResearchTasks};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse, Response};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;

final class TaskController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private ResearchTasks $tasks,
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
            $this->logger->error('Stammbaum task connection failed', [
                'app' => 'stammbaum',
                'exception' => $e,
            ]);
            $r = new JSONResponse(
                [
                    'error' => 'task_error',
                    'message' => 'This task could not be processed. Refresh and try again.',
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
    public function collections(): Response
    {
        return $this->respond(fn($a) => $this->tasks->collections($a));
    }
    #[NoAdminRequired]
    public function review(string $tree): Response
    {
        $b = $this->request->getParams();
        return $this->respond(fn($a) => $this->tasks->review($tree, $a, $b));
    }
    #[NoAdminRequired]
    public function reserve(string $tree): Response
    {
        $b = $this->request->getParams();
        return $this->respond(fn($a) => $this->tasks->reserve($tree, $a, $b), 201);
    }
    #[NoAdminRequired]
    public function complete(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->tasks->complete($tree, $a, $id));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $tree): Response
    {
        $type = $this->request->getParam('sourceType', 'person');
        $person = $this->request->getParam('person', '');
        $id = $this->request->getParam('sourceId', $person);
        return $this->respond(
            fn($a) => $this->tasks->list($tree, $a, is_string($id) ? $id : '', is_string($type) ? $type : ''),
        );
    }
}
