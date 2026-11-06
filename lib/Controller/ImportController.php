<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;
use OCA\Stammbaum\Service\{Imports, Problem};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse, DataDownloadResponse, Response};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;
final class ImportController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private Imports $imports,
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
            $value = $fn($u->getUID());
            $r = $value instanceof Response ? $value : new JSONResponse($value, $status);
        } catch (Problem $e) {
            $r = new JSONResponse(
                ['error' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details],
                $e->status,
            );
        } catch (\Throwable $e) {
            // Original content and exception messages must never enter host logs.
            $this->logger->error('Stammbaum import request failed', [
                'app' => 'stammbaum',
                'exceptionType' => get_class($e),
            ]);
            $r = new JSONResponse(
                [
                    'error' => 'import_error',
                    'message' => 'The import operation failed. Refresh its status before retrying.',
                ],
                500,
            );
        }
        $r->addHeader('Cache-Control', 'private, no-store');
        $r->addHeader('X-Content-Type-Options', 'nosniff');
        return $r;
    }
    private function body(): array
    {
        $b = [];
        foreach (['revision', 'treeRevision', 'sha256', 'planHash', 'acknowledgeReview'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                $b[$key] = $value;
            }
        }
        return $b;
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $tree, int $page = 0): Response
    {
        return $this->respond(fn($a) => $this->imports->list($tree, $a, $page));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->imports->get($tree, $id, $a));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function structure(string $tree, string $id, int $page = 0): Response
    {
        return $this->respond(fn($a) => $this->imports->structure($tree, $id, $a, $page));
    }
    #[NoAdminRequired]
    public function upload(string $tree): Response
    {
        return $this->respond(
            fn($a) => $this->imports->upload($tree, $a, $this->request->getUploadedFile('file') ?? []),
            201,
        );
    }
    #[NoAdminRequired]
    public function confirm(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->imports->confirm($tree, $id, $a, $this->body()));
    }
    #[NoAdminRequired]
    public function cancel(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->imports->cancel($tree, $id, $a, $this->body()));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function original(string $tree, string $id): Response
    {
        return $this->respond(function ($a) use ($tree, $id) {
            $d = $this->imports->original($tree, $id, $a);
            $r = new DataDownloadResponse($d['bytes'], $d['name'], 'application/octet-stream');
            $r->addHeader('Content-Security-Policy', "default-src 'none'; sandbox");
            return $r;
        });
    }
}
