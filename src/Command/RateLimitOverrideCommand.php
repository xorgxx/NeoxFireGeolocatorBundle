<?php

namespace Neox\FireGeolocatorBundle\Command;

use Neox\FireGeolocatorBundle\Service\Cache\StorageInterface;
use Neox\FireGeolocatorBundle\Service\Privacy\AnonymizationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'neox:firegeolocator:rate-limit', description: 'Manage per-identity rate limit overrides (override|show|remove).')]
final class RateLimitOverrideCommand extends Command
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AnonymizationService $privacy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: override | show | remove')
            ->addArgument('subject', InputArgument::OPTIONAL, 'Identifier: IP (e.g., 1.2.3.4), or sessionId with --session, or full rate bucket with --bucket')
            ->addOption('bucket', null, InputOption::VALUE_NONE, 'Treat subject as full standardized rate bucket (e.g., rate_limit:v1:<id>)')
            ->addOption('session', null, InputOption::VALUE_NONE, 'Subject is a sessionId (builds session-based rate bucket)')
            ->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'Use a precomputed ip_hash (32 hex) instead of IP')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'New limit (required for override)')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Window TTL seconds (optional for override)');

        $this->setHelp(<<<TXT
            Examples:
              # Increase limit for a given IP to 600 req / 60s
              php bin/console neox:firegeolocator:rate-limit override 1.2.3.4 --limit 600 --window 60

              # Session-based override
              php bin/console neox:firegeolocator:rate-limit override sid-123 --session --limit 300

              # Using precomputed ip_hash
              php bin/console neox:firegeolocator:rate-limit override --hash aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --limit 1000 --window 120

              # Using a full bucket
              php bin/console neox:firegeolocator:rate-limit override --bucket rate_limit:v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --limit 1000

              # Show / remove
              php bin/console neox:firegeolocator:rate-limit show 1.2.3.4
              php bin/console neox:firegeolocator:rate-limit remove --bucket rate_limit:v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $actionArg = $input->getArgument('action');
        if (!is_string($actionArg) || $actionArg === '') {
            $io->error('Invalid action.');

            return Command::INVALID;
        }
        $action = strtolower($actionArg);

        $subjectArg = $input->getArgument('subject');
        $subject    = is_string($subjectArg) ? trim($subjectArg) : '';
        $asBucket   = (bool) $input->getOption('bucket');
        $isSession  = (bool) $input->getOption('session');
        $hashOpt    = $input->getOption('hash');
        $hash       = is_string($hashOpt) && $hashOpt !== '' ? $hashOpt : null;

        $bucket = null;
        if ($subject !== '' || $asBucket || $hash) {
            if ($asBucket && $subject !== '') {
                $bucket = $subject; // full standardized bucket
            } else {
                if ($isSession && $subject !== '') {
                    $bucket = $this->privacy->buildRateKey(null, $subject);
                } elseif ($hash) {
                    $bucket = 'rate_limit:' . $this->privacy->getAlgoVersion() . ':' . $hash;
                } else {
                    if ($subject === '') {
                        $io->error('Missing subject. Provide an IP or use --hash/--bucket.');

                        return Command::INVALID;
                    }
                    $bucket = $this->privacy->buildRateKey($subject, null);
                }
            }
        }

        return match ($action) {
            'override' => $this->doOverride($io, $bucket, $input->getOption('limit'), $input->getOption('window')),
            'show'     => $this->doShow($io, $bucket),
            'remove'   => $this->doRemove($io, $bucket),
            default    => $this->failInvalid($io, $action),
        };
    }

    private function doOverride(SymfonyStyle $io, ?string $bucket, mixed $limitOpt, mixed $windowOpt): int
    {
        if (!$bucket) {
            $io->error('Missing subject/bucket.');

            return Command::INVALID;
        }
        if (!is_int($limitOpt) && !(is_string($limitOpt) && ctype_digit($limitOpt))) {
            $io->error('--limit is required and must be a positive integer');

            return Command::INVALID;
        }
        $limit  = max(1, (int) $limitOpt);
        $window = 0;
        if (is_int($windowOpt) || (is_string($windowOpt) && ctype_digit($windowOpt))) {
            $window = max(1, (int) $windowOpt);
        }
        $ovrKey  = 'rl_override:' . $bucket;
        $payload = ['limit' => $limit];
        if ($window > 0) {
            $payload['window_ttl'] = $window;
        }
        $ok = $this->storage->set($ovrKey, $payload);
        if ($ok) {
            $io->success(sprintf('Override applied for %s: %s', $bucket, json_encode($payload)));

            return Command::SUCCESS;
        }
        $io->error('Failed to persist override.');

        return Command::FAILURE;
    }

    private function doShow(SymfonyStyle $io, ?string $bucket): int
    {
        if (!$bucket) {
            $io->error('Missing subject/bucket.');

            return Command::INVALID;
        }
        $ovrKey = 'rl_override:' . $bucket;
        $val    = $this->storage->get($ovrKey);
        if ($val === null) {
            $io->writeln('No override for ' . $bucket);

            return Command::SUCCESS;
        }
        $io->writeln(sprintf('Override for %s: %s', $bucket, is_scalar($val) ? (string) $val : json_encode($val)));

        return Command::SUCCESS;
    }

    private function doRemove(SymfonyStyle $io, ?string $bucket): int
    {
        if (!$bucket) {
            $io->error('Missing subject/bucket.');

            return Command::INVALID;
        }
        $ovrKey = 'rl_override:' . $bucket;
        $ok     = $this->storage->delete($ovrKey);
        if ($ok) {
            $io->success('Override removed for ' . $bucket);

            return Command::SUCCESS;
        }
        $io->warning('No override found or deletion failed: ' . $bucket);

        return Command::FAILURE;
    }

    private function failInvalid(SymfonyStyle $io, string $action): int
    {
        $io->error('Unknown action: ' . $action . '. Use: override | show | remove');

        return Command::INVALID;
    }
}
