<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;
use OCA\Stammbaum\Service\{Exports, Problem};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse, DataDownloadResponse, Response};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;
final class ExportController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private Exports $exports,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }
    private function respond(callable $fn, int $status = 200): Response
    {
        try {
            $user = $this->session->getUser();
            if (!$user || !$user->isEnabled()) {
                throw new Problem('unauthenticated', 'Sign in to continue.', 401);
            }
            $value = $fn($user->getUID());
            $r = $value instanceof Response ? $value : new JSONResponse($value, $status);
        } catch (Problem $e) {
            $r = new JSONResponse(
                ['error' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details],
                $e->status,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Stammbaum export operation failed', [
                'app' => 'stammbaum',
                'exception' => $e,
            ]);
            $r = new JSONResponse(
                [
                    'error' => 'export_error',
                    'message' => 'Export could not be processed. Review and create a new export.',
                ],
                500,
            );
        }
        $r->addHeader('Cache-Control', 'private, no-store');
        $r->addHeader('X-Content-Type-Options', 'nosniff');
        return $r;
    }
    #[NoAdminRequired]
    public function preview(string $tree, array $options = []): Response
    {
        return $this->respond(fn($a) => $this->exports->preview($tree, $a, $options));
    }
    #[NoAdminRequired]
    public function create(string $tree): Response
    {
        $b = [];
        foreach (['options', 'revision', 'membershipRevision', 'acknowledged', 'requestId'] as $k) {
            $b[$k] = $this->request->getParam($k);
        }
        return $this->respond(fn($a) => $this->exports->create($tree, $a, $b), 201);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $tree, int $page = 0): Response
    {
        return $this->respond(fn($a) => $this->exports->list($tree, $a, $page));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->exports->get($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function cancel(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->exports->cancel($tree, $id, $a));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(string $tree, string $id, string $part): Response
    {
        return $this->respond(function ($a) use ($tree, $id, $part) {
            $d = $this->exports->download($tree, $id, $a, $part);
            $r = new DataDownloadResponse($d['bytes'], $d['name'], $d['mime']);
            $r->addHeader('Content-Security-Policy', "default-src 'none'; sandbox");
            return $r;
        });
    }
}
