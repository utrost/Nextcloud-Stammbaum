<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Command;
use OCA\Stammbaum\Service\Store;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
/** Post-recovery operation. Host sessions remain under Nextcloud administration. */
final class InvalidateAuthority extends Command
{
    public function __construct(private Store $store)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->setName('stammbaum:invalidate-authority')->setDescription(
            'Revoke invitations and unfinished connection/upload authority after recovery.',
        );
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->store->write(function () {
            $this->store->execute('UPDATE *PREFIX*stb_members SET revision = revision + 1');
            $this->store->execute('UPDATE *PREFIX*stb_invites SET used = 1');
            foreach (['stb_task_links', 'stb_calendar_links', 'stb_contact_links'] as $table) {
                $this->store->execute(
                    "UPDATE *PREFIX*$table SET status = 'authority-invalidated', updated_at = ? WHERE status IN ('pending', 'outcome-unknown')",
                    [time()],
                );
            }
            foreach ($this->store->rows('SELECT id FROM *PREFIX*stb_trees') as $tree) {
                $this->store->bump($tree['id']);
                $this->store->audit(
                    $tree['id'],
                    'system',
                    'recovery_authority_invalidated',
                    null,
                    [],
                    'management',
                );
            }
        });
        $output->writeln(
            'Stammbaum invitations and pending connections invalidated; membership generations advanced. Unfinished uploads require new requests. Existing tree memberships and completed originals remain intact.',
        );
        return self::SUCCESS;
    }
}
