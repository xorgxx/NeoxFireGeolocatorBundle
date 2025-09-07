<?php

namespace Neox\FireGeolocatorBundle\Command;

use Neox\FireGeolocatorBundle\Service\Privacy\AnonymizationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'geolocator:hash-ip', description: 'Compute anonymized hash for an IP without exposing raw IP in logs')]
final class HashIpCommand extends Command
{
    public function __construct(private AnonymizationService $privacy)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('ip', InputArgument::REQUIRED, 'IP address to anonymize');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ip = (string) $input->getArgument('ip');
        try {
            $hash = $this->privacy->anonymizeIp($ip);
            $output->writeln('algo_version: ' . $this->privacy->getAlgoVersion());
            $output->writeln('ip_hash: ' . $hash);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
