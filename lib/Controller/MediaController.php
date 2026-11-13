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
    public function index(
        string $tree,
        string $person = '',
        int $page = 0,
        string $type = '',
        string $personQuery = '',
        string $eventQuery = '',
        string $place = '',
        bool $missing = false,
        string $event = '',
    ): Response {
        $filters = compact('type', 'personQuery', 'eventQuery', 'place', 'missing', 'event');
        return $this->respond(fn($a) => $this->media->list($tree, $a, $person, $page, $filters));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function events(string $tree, string $query = '', int $page = 0): Response
    {
        return $this->respond(fn($a) => $this->media->events($tree, $a, $query, $page));
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
        string $visibility = '',
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
    public function selectFile(string $tree, string $path): Response
    {
        return $this->respond(fn($a) => $this->media->selectFile($tree, $a, $path));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function referenceReview(string $tree, string $record): Response
    {
        return $this->respond(fn($a) => $this->media->referenceReview($tree, $a, $record));
    }
    #[NoAdminRequired]
    public function importFile(
        string $tree,
        int $fileId,
        string $etag,
        string $requestId,
        string $title = '',
        string $personId = '',
        string $visibility = '',
        string $referenceRecordId = '',
        int $referenceField = -1,
        int $referenceRevision = 0,
    ): Response {
        return $this->respond(
            fn($a) => $this->media->importFile($tree, $a, $fileId, $etag, [
                'requestId' => $requestId,
                'personId' => $personId,
                'visibility' => $visibility,
                ...$referenceRecordId !== ''
                    ? [
                        'referenceRecordId' => $referenceRecordId,
                        'referenceField' => $referenceField,
                        'referenceRevision' => $referenceRevision,
                    ]
                    : [],
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
            foreach (
                ['title', 'visibility', 'revision', 'assignments', 'confirmRelease', 'metadata']
                as $key
            ) {
                $value = $this->request->getParam($key);
                if ($value !== null) {
                    $body[$key] = $value;
                }
            }
            return $this->media->save($tree, $id, $a, $body);
        });
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function trash(string $tree, int $page = 0): Response
    {
        return $this->respond(fn($a) => $this->media->trash($tree, $a, $page));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function impact(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->media->impact($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function delete(string $tree, string $id): Response
    {
        return $this->respond(
            fn($a) => $this->media->lifecycle(
                $tree,
                $id,
                $a,
                $this->request->getParam('revision'),
                $this->request->getParam('treeRevision'),
                false,
            ),
        );
    }
    #[NoAdminRequired]
    public function restore(string $tree, string $id): Response
    {
        return $this->respond(
            fn($a) => $this->media->lifecycle(
                $tree,
                $id,
                $a,
                $this->request->getParam('revision'),
                null,
                true,
            ),
        );
    }
    private function binary(
        string $tree,
        string $id,
        string $actor,
        bool $preview,
        string $person = '',
        int $size = 600,
        int $page = 1,
    ): Response {
        $d = $this->media->download($tree, $id, $actor, $preview, $person, $size, $page);
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
    public function previewInfo(string $tree, string $id): Response
    {
        return $this->respond(fn($a) => $this->media->previewInfo($tree, $id, $a));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function preview(
        string $tree,
        string $id,
        string $person = '',
        int $size = 600,
        int $page = 1,
    ): Response {
        return $this->respond(fn($a) => $this->binary($tree, $id, $a, true, $person, $size, $page));
    }
}
