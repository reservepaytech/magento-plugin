<?php

namespace Reservepay\Payment\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Reservepay\Payment\Cron\Reconcile as ReconcileJob;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs one batch of the reconcile cron job, for stores without a cron runner or to settle orders on demand.
 */
class Reconcile extends Command
{
    private const BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly State $appState,
        private readonly ReconcileJob $job
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('reservepay:reconcile')
            ->setDescription('Check Reservepay orders with an attempt from the last 72 hours with Reservepay, least recently checked first')
            ->addOption(self::BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Orders to check', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption(self::BATCH_SIZE);
        if ($batchSize < 1) {
            $output->writeln('<error>--batch-size must be at least 1</error>');
            return Command::INVALID;
        }
        // Set, not emulated, as cron:run does: the order email cannot load its theme under an emulated area.
        $this->appState->setAreaCode(Area::AREA_CRONTAB);
        $outcomes = $this->job->run($batchSize);
        $output->writeln($outcomes ? json_encode($outcomes) : 'No Reservepay orders to check');
        return Command::SUCCESS;
    }
}
