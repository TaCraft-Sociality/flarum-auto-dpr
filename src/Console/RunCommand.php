<?php

namespace TacraftSociality\AutoDpr\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TacraftSociality\AutoDpr\AutoDprService;
use Throwable;

/**
 * php flarum auto-dpr:run [--force]
 */
class RunCommand extends Command
{
    public function __construct(?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setName('auto-dpr:run')
            ->setDescription('永久删除已被用户删除（隐藏）的讨论和回复。')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '忽略总开关与执行间隔，立即执行一次。');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            /** @var AutoDprService $service */
            $service = resolve(AutoDprService::class);
        } catch (Throwable $e) {
            $output->writeln('<error>无法加载自动清理服务：'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $result = $service->run((bool) $input->getOption('force'), 'cli');
        $status = $result['status'] ?? 'ran';

        if ($status === 'disabled') {
            $output->writeln('<comment>自动清理未启用，已跳过。可在后台设置中开启。</comment>');
        } elseif ($status === 'not_due') {
            $output->writeln('<comment>尚未到达设定的执行间隔，已跳过。</comment>');
        } elseif ($status === 'busy') {
            $output->writeln('<comment>另一次清理正在执行，已跳过。</comment>');
        }

        $output->writeln(sprintf(
            '本次永久删除：讨论 %d 条，回复 %d 条。',
            (int) ($result['discussions'] ?? 0),
            (int) ($result['posts'] ?? 0)
        ));

        if (! empty($result['error'])) {
            $output->writeln('<error>部分操作失败：'.$result['error'].'</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
