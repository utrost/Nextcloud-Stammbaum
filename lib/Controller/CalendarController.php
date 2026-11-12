<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;
use OCA\Stammbaum\Service\{CalendarAppointments, Problem};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse, Response};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;

final class CalendarController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private CalendarAppointments $appointments,
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
            $this->logger->error('Stammbaum calendar connection failed', [
                'app' => 'stammbaum',
                'exception' => $e,
            ]);
            $r = new JSONResponse(
                [
                    'error' => 'calendar_error',
                    'message' => 'This calendar event could not be processed. Refresh and try again.',
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
        return $this->respond(fn($a) => $this->appointments->collections($a));
    }
    #[NoAdminRequired]
    public function review(string $tree): Response
    {
        return $this->respond(fn($a) => $this->appointments->review($tree, $a, $this->request->getParams()));
    }
    #[NoAdminRequired]
    public function create(string $tree): Response
    {
        return $this->respond(
            fn($a) => $this->appointments->create($tree, $a, $this->request->getParams()),
            201,
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $tree): Response
    {
        $person = $this->request->getParam('person', '');
        return $this->respond(
            fn($a) => $this->appointments->list($tree, $a, is_string($person) ? $person : ''),
        );
    }
    #[NoAdminRequired]
    public function unlink(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->appointments->unlink($tree, $a, $id));
    }
}
