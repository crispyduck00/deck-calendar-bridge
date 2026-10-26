<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Controller;

use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCA\DeckCalendarBridge\Service\RuleRevisionConflict;
use OCA\DeckCalendarBridge\Sync\Scanner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;

/** Admin-only rule save is the explicit, bounded delegation capability. */
final class RulesController extends Controller {
    public function __construct(IRequest $request,private RuleRepository $repository,
        private IUserSession $session,private IGroupManager $groups,private Scanner $scanner) {
        parent::__construct('deckcalendarbridge',$request);
    }
    private function admin(): bool {
        return $this->session->getUser()!==null && $this->groups->isAdmin($this->session->getUser()->getUID());
    }
    public function index(): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        return new DataResponse($this->repository->read());
    }
    public function update(): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        if (!preg_match('~^application/json(?:\s*;|\s*$)~i',$this->request->getHeader('Content-Type'))) {
            return new DataResponse(['error'=>'Content-Type must be application/json'],415);
        }
        try {
            $this->request->throwDecodingExceptionIfAny();
            $saved=$this->repository->replace($this->request->getParam('rules'),$this->request->getParam('revision'));
        } catch (RuleRevisionConflict|\OCP\Lock\LockedException $e) { return new DataResponse(['error'=>'Rules changed or are busy; reload before saving'],409);
        } catch (\InvalidArgumentException|\DomainException|\JsonException|\TypeError $e) { return new DataResponse(['error'=>$e->getMessage()],400); }
        try { $saved['backfill']=$this->scanner->scan(); }
        catch (\Throwable) { $saved['backfill']=['reason'=>'scan_pending']; }
        return new DataResponse($saved);
    }
}
