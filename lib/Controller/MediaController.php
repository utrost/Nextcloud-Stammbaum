<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;
use OCA\Stammbaum\Service\{Media, Problem};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse, DataDownloadResponse, Response};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;
final class MediaController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private Media $media,
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
            $this->logger->error('Stammbaum media operation failed', [
                'app' => 'stammbaum',
                'exception' => $e,
            ]);
            $r = new JSONResponse(
                [
                    'error' => 'media_error',
                    'message' =>
                        'Media could not be processed. Your original has not been published; retry the upload.',
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
    public function index(string $tree, string $person = '', int $page = 0): Response
    {
        return $this->respond(fn($a) => $this->media->list($tree, $a, $person, $page));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->media->get($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function upload(
        string $tree,
        string $requestId,
        string $personId = '',
        string $visibility = 'members',
        string $title = '',
    ): Response {
        return $this->respond(
            fn($a) => $this->media->upload($tree, $a, $this->request->getUploadedFile('file') ?? [], [
                'requestId' => $requestId,
                'personId' => $personId,
                'visibility' => $visibility,
                ...$title !== '' ? ['title' => $title] : [],
            ]),
            201,
        );
    }
    #[NoAdminRequired]
    public function update(string $tree, string $id): Response
    {
        return $this->respond(function ($a) use ($tree, $id) {
            $body = [];
            foreach (['title', 'visibility', 'revision', 'assignments', 'confirmRelease'] as $key) {
                $value = $this->request->getParam($key);
                if ($value !== null) {
                    $body[$key] = $value;
                }
            }
            return $this->media->save($tree, $id, $a, $body);
        });
    }
    private function binary(
        string $tree,
        string $id,
        string $actor,
        bool $preview,
        string $person = '',
    ): Response {
        $d = $this->media->download($tree, $id, $actor, $preview, $person);
        $r = new DataDownloadResponse($d['bytes'], $d['name'], $d['mime']);
        if ($preview) {
            $r->addHeader('Content-Disposition', 'inline; filename="preview.png"');
        }
        $r->addHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        return $r;
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function original(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->binary($tree, $id, $a, false));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function preview(string $tree, string $id, string $person = ''): Response
    {
        return $this->respond(fn($a) => $this->binary($tree, $id, $a, true, $person));
    }
}
