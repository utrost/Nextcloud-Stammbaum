<?php
declare(strict_types=1);
namespace OCA\Stammbaum\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\{IBootstrap, IRegistrationContext, IBootContext};
final class Application extends App implements IBootstrap
{
    public const APP_ID = 'stammbaum';
    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }
    public function register(IRegistrationContext $context): void
    {
        foreach (
            [
                \OCP\User\Events\UserDeletedEvent::class,
                \OCP\User\Events\UserCreatedEvent::class,
                \OCP\User\Events\UserChangedEvent::class,
                \OCP\User\Events\PasswordUpdatedEvent::class,
            ]
            as $event
        ) {
            $context->registerEventListener($event, \OCA\Stammbaum\Listener\AccountLifecycle::class);
        }
    }
    public function boot(IBootContext $context): void {}
}
