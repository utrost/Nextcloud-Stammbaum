<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Controller;
use OCA\Stammbaum\Service\{Trees, People, Family, Evidence, Records, Contexts, Preferences, Problem};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\AppFramework\Http\JSONResponse;
use OCP\{IRequest, IUserSession};
use Psr\Log\LoggerInterface;
final class ApiController extends Controller
{
    private const PERSON = [
        'givenName',
        'familyName',
        'displayNote',
        'livingStatus',
        'sex',
        'visibility',
        'birthDate',
        'deathDate',
        'birthPlace',
        'deathPlace',
        'confirmUnnamed',
        'confirmRelease',
        'revision',
        'requestId',
    ];
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private Trees $treesService,
        private People $peopleService,
        private Family $family,
        private Evidence $evidence,
        private Records $recordsService,
        private Contexts $contextsService,
        private Preferences $preferences,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }
    private function actor(): string
    {
        $user = $this->session->getUser();
        if (!$user || !$user->isEnabled()) {
            throw new Problem('unauthenticated', 'Sign in to continue.', 401);
        }
        return $user->getUID();
    }
    private function respond(callable $fn, int $status = 200): JSONResponse
    {
        try {
            $r = new JSONResponse($fn($this->actor()), $status);
        } catch (Problem $e) {
            $r = new JSONResponse(
                ['error' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details],
                $e->status,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Stammbaum request failed', ['app' => 'stammbaum', 'exception' => $e]);
            $r = new JSONResponse(
                [
                    'error' => 'server_error',
                    'message' =>
                        'The operation could not be completed. Retry after checking the current information.',
                ],
                500,
            );
        }
        $r->addHeader('Cache-Control', 'private, no-store');
        return $r;
    }
    private function body(array $allowed): array
    {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (!is_string($raw) || strlen($raw) > 65536) {
            throw new Problem('payload_too_large', 'The request is too large.', 413);
        }
        try {
            $o = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            $b = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Problem('invalid_json', 'Send a JSON object.');
        }
        if (!($o instanceof \stdClass)) {
            throw new Problem('invalid_json', 'Send a JSON object.');
        }
        foreach (array_keys($b) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new Problem('unknown_field', 'The request contains an unsupported field.');
            }
        }
        return $b;
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function trees(): JSONResponse
    {
        return $this->respond(fn($a) => ['items' => $this->treesService->list($a)]);
    }
    #[NoAdminRequired]
    public function createTree(): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->treesService->create($a, $this->body(['name', 'description', 'requestId'])),
            201,
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function tree(string $tree): JSONResponse
    {
        return $this->respond(fn($a) => $this->treesService->get($tree, $a));
    }
    #[NoAdminRequired]
    public function updateTree(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->treesService->update(
                $tree,
                $a,
                $this->body(['name', 'description', 'homeId', 'revision']),
            ),
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function members(string $tree): JSONResponse
    {
        return $this->respond(fn($a) => ['items' => $this->treesService->members($tree, $a)]);
    }
    #[NoAdminRequired]
    public function member(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->treesService->member(
                $tree,
                $a,
                $this->body(['userId', 'role', 'exportAllowed', 'revision', 'remove']),
            ),
        );
    }
    #[NoAdminRequired]
    public function invite(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->treesService->invite($tree, $a, $this->body(['role', 'exportAllowed'])),
            201,
        );
    }
    #[NoAdminRequired]
    public function reviewInvite(): JSONResponse
    {
        return $this->respond(fn($a) => $this->treesService->accept($a, $this->body(['token']), false));
    }
    #[NoAdminRequired]
    public function acceptInvite(): JSONResponse
    {
        return $this->respond(fn($a) => $this->treesService->accept($a, $this->body(['token']), true));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function people(
        string $tree,
        string $query = '',
        int $page = 0,
        bool $deleted = false,
    ): JSONResponse {
        return $this->respond(fn($a) => $this->peopleService->list($tree, $a, $query, $page, $deleted));
    }
    #[NoAdminRequired]
    public function createPerson(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->peopleService->create($tree, $a, $this->body(self::PERSON)),
            201,
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function person(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->peopleService->get($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function updatePerson(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->peopleService->update($tree, $id, $a, $this->body(self::PERSON)),
        );
    }
    #[NoAdminRequired]
    public function deletePerson(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->peopleService->remove(
                $tree,
                $id,
                $a,
                $this->body(['revision', 'confirmed', 'treeRevision']),
            ),
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deletionImpact(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->peopleService->impact($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function restorePerson(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->peopleService->remove(
                $tree,
                $id,
                $a,
                $this->body(['revision', 'confirmed']),
                true,
            ),
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function claims(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => ['items' => $this->peopleService->claims($tree, $id, $a)]);
    }
    #[NoAdminRequired]
    public function prefer(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->peopleService->prefer($tree, $id, $a, $this->body(['claimId', 'revision'])),
        );
    }
    #[NoAdminRequired]
    public function assess(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->peopleService->assess(
                $tree,
                $id,
                $a,
                $this->body(['claimId', 'revision', 'assessment']),
            ),
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function history(string $tree, string $id, int $page = 0): JSONResponse
    {
        return $this->respond(fn($a) => $this->peopleService->history($tree, $id, $a, $page));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function relations(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->family->list($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function connect(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->family->connect(
                $tree,
                $id,
                $a,
                $this->body([
                    'requestId',
                    'revision',
                    'direction',
                    'parentType',
                    'personId',
                    'person',
                    'targetRevision',
                    'confirmEpisode',
                ]),
            ),
            201,
        );
    }
    #[NoAdminRequired]
    public function removeRelation(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->family->remove($tree, $id, $a, $this->body(['revision'])));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function graph(
        string $tree,
        string $focus = '',
        string $mode = 'family',
        int $up = 3,
        int $down = 2,
        mixed $expand = [],
    ): JSONResponse {
        return $this->respond(fn($a) => $this->family->graph($tree, $a, $focus, $mode, $up, $down, $expand));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function sources(string $tree, string $query = '', int $page = 0): JSONResponse
    {
        return $this->respond(fn($a) => $this->evidence->sources($tree, $a, $query, $page));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function invitations(string $tree): JSONResponse
    {
        return $this->respond(fn($a) => ['items' => $this->treesService->invitations($tree, $a)]);
    }
    #[NoAdminRequired]
    public function revokeInvitation(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->treesService->revokeInvitation($tree, $id, $a));
    }
    private const RECORD = [
        'originId',
        'category',
        'type',
        'title',
        'text',
        'date',
        'place',
        'ongoing',
        'fields',
        'participants',
        'confirmedParticipants',
        'visibility',
        'confirmRelease',
        'revision',
        'requestId',
    ];
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function records(string $tree, string $person, string $category = '', int $page = 0): JSONResponse
    {
        return $this->respond(fn($a) => $this->recordsService->list($tree, $a, $person, $category, $page));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function record(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->recordsService->get($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function createRecord(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->recordsService->save($tree, null, $a, $this->body(self::RECORD)),
            201,
        );
    }
    #[NoAdminRequired]
    public function updateRecord(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->recordsService->save($tree, $id, $a, $this->body(self::RECORD)),
        );
    }
    #[NoAdminRequired]
    public function preferRecord(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->recordsService->prefer($tree, $id, $a, $this->body(['revision'])),
        );
    }
    private const CONTEXT = [
        'label',
        'visibility',
        'revision',
        'requestId',
        'members',
        'relationIds',
        'confirmRelease',
    ];
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function contexts(string $tree, string $person = '', int $page = 0): JSONResponse
    {
        return $this->respond(fn($a) => $this->contextsService->list($tree, $a, $person, $page));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function context(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->contextsService->get($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function createContext(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->contextsService->save($tree, null, $a, $this->body(self::CONTEXT)),
            201,
        );
    }
    #[NoAdminRequired]
    public function updateContext(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->contextsService->save($tree, $id, $a, $this->body(self::CONTEXT)),
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function preferences(): JSONResponse
    {
        return $this->respond(fn($a) => $this->preferences->locale($a));
    }
    #[NoAdminRequired]
    public function savePreferences(): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->preferences->saveLocale($a, $this->body(['locale', 'revision'])),
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function views(string $tree): JSONResponse
    {
        return $this->respond(fn($a) => $this->preferences->views($tree, $a));
    }
    #[NoAdminRequired]
    public function saveHome(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->preferences->saveHome($tree, $a, $this->body(['homeId', 'revision'])),
        );
    }
    #[NoAdminRequired]
    public function saveView(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->preferences->saveView(
                $tree,
                $a,
                $this->body([
                    'label',
                    'focus',
                    'selected',
                    'mode',
                    'up',
                    'down',
                    'camera',
                    'revision',
                    'requestId',
                ]),
            ),
            201,
        );
    }
    #[NoAdminRequired]
    public function deleteView(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->preferences->deleteView($tree, $id, $a, $this->body(['revision'])),
        );
    }
    private const SOURCE = [
        'title',
        'type',
        'author',
        'publication',
        'repository',
        'callNumber',
        'url',
        'description',
        'visibility',
        'revision',
        'requestId',
    ];
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function source(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->evidence->source($tree, $id, $a));
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function citation(string $tree, string $id): JSONResponse
    {
        return $this->respond(fn($a) => $this->evidence->citation($tree, $id, $a));
    }
    #[NoAdminRequired]
    public function createSource(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->evidence->saveSource($tree, null, $a, $this->body(self::SOURCE)),
            201,
        );
    }
    #[NoAdminRequired]
    public function updateSource(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->evidence->saveSource($tree, $id, $a, $this->body(self::SOURCE)),
        );
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function citations(
        string $tree,
        string $person = '',
        string $source = '',
        int $page = 0,
    ): JSONResponse {
        return $this->respond(fn($a) => $this->evidence->citations($tree, $a, $person, $source, $page));
    }
    private const CITATION = [
        'sourceId',
        'locator',
        'quotation',
        'url',
        'documentDate',
        'accessDate',
        'reliability',
        'reasoning',
        'visibility',
        'revision',
        'requestId',
        'targets',
    ];
    #[NoAdminRequired]
    public function createCitation(string $tree): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->evidence->saveCitation($tree, null, $a, $this->body(self::CITATION)),
            201,
        );
    }
    #[NoAdminRequired]
    public function updateCitation(string $tree, string $id): JSONResponse
    {
        return $this->respond(
            fn($a) => $this->evidence->saveCitation($tree, $id, $a, $this->body(self::CITATION)),
        );
    }
}
