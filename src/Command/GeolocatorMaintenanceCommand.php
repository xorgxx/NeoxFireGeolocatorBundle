<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Command;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'neox:firegeolocator:maintenance',
    description: 'Active/Désactive le mode maintenance (TTL et commentaire pris en charge)'
)]
final class GeolocatorMaintenanceCommand extends Command
{
    private const CACHE_KEY = 'neox_fire_geolocator_maintenance_flag';

    public function __construct(private CacheItemPoolInterface $cache)
    {
        // Ensure the command has a name when instantiated manually (outside the container)
        parent::__construct('neox:firegeolocator:maintenance');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: enable | disable | status')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'TTL en secondes (prioritaire sur --duration)')
            ->addOption('duration', 'd', InputOption::VALUE_REQUIRED, 'Durée lisible (ex: "15 minutes", "1 hour", "2 days")')
            ->addOption('comment', 'm', InputOption::VALUE_REQUIRED, 'Commentaire/raison de la maintenance')
            ->setHelp(<<<'HELP'
                Gère le mode maintenance de l’application.

                USAGE
                  neox:firegeolocator:maintenance <action> [options]

                ACTIONS
                  enable|on     Active la maintenance
                  disable|off   Désactive la maintenance
                  status        Affiche l’état courant
                  help|?        Affiche cette aide (équivalent à --help)

                OPTIONS
                  --ttl=SECONDS         TTL en secondes (expire automatiquement après SECONDS)
                  -d, --duration=TEXT   Durée lisible (ex: "30 minutes", "2 hours", "1 day")
                                        Si --ttl est présent, il est prioritaire sur --duration.
                  -m, --comment=TEXT    Commentaire libre (raison, ETA, contact)

                COMPORTEMENT
                  • enable: stocke un drapeau en cache avec horodatage, commentaire et expiration (si TTL ou durée fournis).
                  • disable: supprime le drapeau de maintenance du cache.
                  • status: affiche l’état, les horodatages since/until, TTL restant et le commentaire, si présents.

                EXEMPLES
                  # Activer indéfiniment avec commentaire
                  php bin/console neox:firegeolocator:maintenance enable -m "Maintenance planifiée"

                  # Activer avec TTL 1800s (30 min)
                  php bin/console neox:firegeolocator:maintenance enable --ttl=1800

                  # Activer pour 1 heure via durée lisible
                  php bin/console neox:firegeolocator:maintenance enable -d "1 hour" -m "Upgrade base de données"

                  # Voir l’état courant
                  php bin/console neox:firegeolocator:maintenance status

                  # Désactiver
                  php bin/console neox:firegeolocator:maintenance disable

                CODES DE SORTIE
                  0  Succès
                  2  Action invalide

                NOTE
                  Assurez-vous qu’un listener KernelEvents::REQUEST consulte ce drapeau (clé cache: "geolocator_maintenance_flag")
                  pour bloquer les requêtes et respecter les whitelists (rôles/paths/IPs).
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $actionArg = $input->getArgument('action');
        if (!is_string($actionArg) || $actionArg === '') {
            $io->error('Action invalide.');

            return Command::INVALID;
        }
        $action  = strtolower($actionArg);
        $ttlOpt  = $input->getOption('ttl');
        $durOpt  = $input->getOption('duration');
        $comment = $input->getOption('comment');

        return match ($action) {
            'enable', 'on' => $this->enable($io, $ttlOpt, $durOpt, $comment),
            'disable', 'off' => $this->disable($io),
            'status' => $this->status($io),
            'help', '?' => $this->showHelp($io),
            default => $this->invalid($io, $action),
        };
    }

    private function enable(SymfonyStyle $io, mixed $ttlOpt, mixed $durationOpt, mixed $comment): int
    {
        $ttl  = $this->computeTtl($ttlOpt, $durationOpt);
        $item = $this->cache->getItem(self::CACHE_KEY);

        $now   = new \DateTimeImmutable();
        $until = $ttl !== null ? $now->add(new \DateInterval('PT' . $ttl . 'S')) : null;

        $payload = [
            'enabled' => true,
            'since'   => $now->format(DATE_ATOM),
            'until'   => $until?->format(DATE_ATOM),
            'ttl'     => $ttl,
            'comment' => is_scalar($comment) && (string) $comment !== '' ? (string) $comment : null,
        ];

        $item->set($payload);
        if ($ttl !== null) {
            $item->expiresAfter($ttl);
        }
        $this->cache->save($item);

        $io->success(sprintf(
            'Maintenance activée%s.',
            $ttl !== null ? ' (TTL=' . $ttl . 's, jusqu\'à ' . $payload['until'] . ')' : ''
        ));
        if (!empty($payload['comment'])) {
            $io->writeln('Commentaire: ' . $payload['comment']);
        }

        return Command::SUCCESS;
    }

    private function disable(SymfonyStyle $io): int
    {
        $this->cache->deleteItem(self::CACHE_KEY);
        $io->success('Maintenance désactivée.');

        return Command::SUCCESS;
    }

    private function status(SymfonyStyle $io): int
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if (!$item->isHit()) {
            $io->writeln('Maintenance: désactivée');

            return Command::SUCCESS;
        }

        $payload = $item->get();
        $payload = is_array($payload) ? $payload : [];
        $enabled = (bool) ($payload['enabled'] ?? false);
        $since   = $payload['since']   ?? null;
        $until   = $payload['until']   ?? null;
        $ttl     = $payload['ttl']     ?? null;
        $comment = $payload['comment'] ?? null;

        $io->writeln('Maintenance: ' . ($enabled ? 'activée' : 'désactivée'));
        if ($since) {
            $io->writeln('Depuis: ' . $since);
        }
        if ($until) {
            $io->writeln('Jusqu\'à: ' . $until);
        }
        if ($ttl !== null) {
            $io->writeln('TTL restant (approx): ' . $ttl . 's');
        }
        if (!empty($comment)) {
            $io->writeln('Commentaire: ' . $comment);
        }

        return Command::SUCCESS;
    }

    private function invalid(SymfonyStyle $io, string $action): int
    {
        $io->error('Action invalide: ' . $action . '. Utilisez: enable | disable | status | help');

        return Command::INVALID;
    }

    private function showHelp(SymfonyStyle $io): int
    {
        // Affiche un synopsis utile puis le bloc d’aide configuré
        $io->writeln($this->getSynopsis());
        $help = trim((string) $this->getHelp());
        if ($help !== '') {
            $io->writeln('');
            $io->writeln($help);
        }

        return Command::SUCCESS;
    }

    // Calcule un TTL en secondes à partir de --ttl (prioritaire) ou --duration (format lisible). Null si non fourni.
    private function computeTtl(mixed $ttlOpt, mixed $durationOpt): ?int
    {
        if (is_int($ttlOpt) || (is_string($ttlOpt) && ctype_digit($ttlOpt))) {
            return max(1, (int) $ttlOpt);
        }
        if (is_string($durationOpt) && $durationOpt !== '') {
            $ts = @strtotime('+' . $durationOpt);
            if ($ts !== false) {
                return max(1, $ts - time());
            }
        }

        return null;
    }
}
