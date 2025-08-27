<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Command;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ProviderResultDTO;
use Neox\FireGeolocatorBundle\Provider\DsnHttpProvider;
use Neox\FireGeolocatorBundle\Provider\Mapper\IpApiMapper;
use Neox\FireGeolocatorBundle\Provider\Mapper\IpInfoMapper;
use Neox\FireGeolocatorBundle\Provider\Mapper\MaxmindDataMapper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'neox:firegeolocator:test-provider', description: 'Teste un provider de géolocalisation configuré')]
class TestProviderCommand extends Command
{
    /**
     * @param array{
     *   providers?: array{
     *     list?: array<string, array{
     *       dsn?: string,
     *       variables?: array<string, scalar|array|null>
     *     }>
     *   }
     * } $geolocatorConfig
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly array $geolocatorConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::OPTIONAL, 'Alias du provider (ex: findip, ipapi, ipinfo)')
            ->addArgument('ip', InputArgument::OPTIONAL, 'Adresse IP à tester (ex: 1.2.3.4). Si omise: 127.0.0.1')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Lister les providers disponibles')
            ->addOption('compact', null, InputOption::VALUE_NONE, 'Affichage compact sur une seule ligne')
            ->addOption('normalized', null, InputOption::VALUE_NONE, 'Afficher les données normalisées (JSON)')
            ->addOption('simulate', null, InputOption::VALUE_NONE, 'Activer le mode simulation (données factices)')
            ->addOption('validate', null, InputOption::VALUE_NONE, 'Valider automatiquement les champs essentiels')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Inclure les données brutes (raw) quand possible')
            ->addOption('health', null, InputOption::VALUE_NONE, 'Vérifier la santé des providers (tous ou un seul si précisé)')
        ;

        $examples = "Exemples d'utilisation:" . PHP_EOL .
            '  php bin/console neox:firegeolocator:test-provider findip' . PHP_EOL .
            '  php bin/console neox:firegeolocator:test-provider findip 1.2.3.4 --all' . PHP_EOL .
            '  php bin/console neox:firegeolocator:test-provider findip --compact' . PHP_EOL .
            '  php bin/console neox:firegeolocator:test-provider findip --simulate' . PHP_EOL .
            '  php bin/console neox:firegeolocator:test-provider --list' . PHP_EOL . PHP_EOL .
            'Options:' . PHP_EOL .
            '  --compact     Affichage compact sur une seule ligne' . PHP_EOL .
            '  --normalized  Afficher les données normalisées' . PHP_EOL .
            '  --simulate    Activer le mode simulation (données factices)' . PHP_EOL .
            '  --validate    Valider automatiquement les champs essentiels';
        $this->setHelp($examples);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providers = (array) ($this->geolocatorConfig['providers']['list'] ?? []);

        if ($input->getOption('list')) {
            if (!$providers) {
                $output->writeln('<comment>Aucun provider configuré.</comment>');

                return Command::SUCCESS;
            }
            $output->writeln('<info>Providers disponibles:</info>');
            foreach ($providers as $alias => $def) {
                $dsn = is_array($def) ? ($def['dsn'] ?? 'n/a') : 'n/a';
                $output->writeln(sprintf('  - %s: %s', (string) $alias, (string) $dsn));
            }

            return Command::SUCCESS;
        }

        $provArg = $input->getArgument('provider');
        $alias   = is_string($provArg) ? $provArg : '';
        $health  = (bool) $input->getOption('health');
        if ($health) {
            if (!$providers) {
                $output->writeln('<comment>Aucun provider configuré.</comment>');

                return Command::SUCCESS;
            }
            $targets = [];
            if ($alias !== '') {
                if (!isset($providers[$alias])) {
                    $output->writeln(sprintf('<error>Provider "%s" non trouvé.</error>', $alias));

                    return Command::FAILURE;
                }
                $targets[$alias] = $providers[$alias];
            } else {
                $targets = $providers;
            }
            $anyFail = false;
            foreach ($targets as $a => $_def) {
                [$ctx, $err] = $this->fetchFromProvider($providers, (string) $a, '1.2.3.4');
                if ($ctx instanceof GeoApiContextDTO) {
                    $output->writeln(sprintf('<info>[%s] OK</info>', (string) $a));
                } else {
                    $anyFail = true;
                    $suffix  = $err ? (': ' . (string) $err) : '';
                    $output->writeln(sprintf('<error>[%s] FAIL</error>%s', (string) $a, $suffix));
                }
            }

            return $anyFail ? Command::FAILURE : Command::SUCCESS;
        }
        if ($alias === '') {
            $output->writeln('<error>Veuillez préciser un alias de provider, ou utilisez --list.</error>');

            return Command::INVALID;
        }
        $ipArg = $input->getArgument('ip');
        $ip    = is_string($ipArg) && $ipArg !== '' ? $ipArg : '127.0.0.1';

        $simulate   = (bool) $input->getOption('simulate');
        $compact    = (bool) $input->getOption('compact');
        $normalized = (bool) $input->getOption('normalized');
        $validate   = (bool) $input->getOption('validate');
        $withRaw    = (bool) $input->getOption('all');

        $context = null;
        $error   = null;

        if ($simulate) {
            $context = $this->fakeContext($ip);
        } else {
            [$context, $error] = $this->fetchFromProvider($providers, $alias, $ip);
        }

        if (!$context instanceof GeoApiContextDTO) {
            $output->writeln(sprintf('<error>Échec</error> Provider=%s IP=%s%s', $alias, $ip, $error ? (' • ' . $error) : ''));

            return Command::FAILURE;
        }

        if ($validate) {
            $validation = $this->validateContext($context);
            if ($validation !== true) {
                /* @var array<int,string> $validation */
                $output->writeln('<error>Validation échouée:</error> ' . implode('; ', $validation));
                // Continue to show output but use different exit code (2)
            }
        }

        if ($normalized) {
            $data = $this->toNormalizedArray($context, $withRaw);
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $output->writeln($json === false ? '{}' : $json);
        } elseif ($compact) {
            $output->writeln($this->formatCompact($alias, $context));
        } else {
            $output->writeln($this->formatPretty($alias, $context, $withRaw));
        }

        if ($validate) {
            $validation = $this->validateContext($context);
            if ($validation !== true) {
                return 2; // non-zero to indicate validation errors
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array{dsn?: string, variables?: array<string, scalar|array|null>}> $providers
     *
     * @return array{0: GeoApiContextDTO|null, 1: string|null}
     */
    private function fetchFromProvider(array $providers, string $alias, string $ip): array
    {
        $def = $providers[$alias] ?? null;
        if (!$def || !is_array($def) || !isset($def['dsn'])) {
            return [null, 'Provider non trouvé: ' . $alias];
        }
        $dsn = (string) $def['dsn'];
        // Déduire le mapper via le schéma avant le "+"
        $schemePos = strpos($dsn, '+');
        $scheme    = $schemePos !== false ? strtolower(substr($dsn, 0, $schemePos)) : '';
        $mapper    = match ($scheme) {
            'ipapi'  => new IpApiMapper(),
            'findip' => new MaxmindDataMapper(),
            default  => new IpInfoMapper(),
        };
        $provider = new DsnHttpProvider($this->client, $dsn, (array) ($def['variables'] ?? []), $mapper);
        try {
            /** @var ProviderResultDTO $res */
            $res = $provider->fetch($ip);
            if ($res->ok && $res->context instanceof GeoApiContextDTO) {
                return [$res->context, null];
            }

            return [null, $res->error ?? 'Résultat invalide'];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * @return array{
     *   ip: string|null,
     *   country: string|null,
     *   countryCode: string|null,
     *   region: string|null,
     *   city: string|null,
     *   lat: float|int|null,
     *   lon: float|int|null,
     *   isp: string|null,
     *   asn: string|null,
     *   proxy: bool,
     *   hosting: bool,
     *   isVpn: bool,
     *   raw?: mixed
     * }
     */
    private function toNormalizedArray(GeoApiContextDTO $ctx, bool $withRaw): array
    {
        $data = [
            'ip'          => $ctx->ip,
            'country'     => $ctx->country,
            'countryCode' => $ctx->countryCode,
            'region'      => $ctx->region,
            'city'        => $ctx->city,
            'lat'         => $ctx->lat,
            'lon'         => $ctx->lon,
            'isp'         => $ctx->isp,
            'asn'         => $ctx->asn,
            'proxy'       => (bool) ($ctx->proxy ?? false),
            'hosting'     => (bool) ($ctx->hosting ?? false),
            'isVpn'       => (bool) (($ctx->proxy ?? false) || ($ctx->hosting ?? false)),
        ];
        if ($withRaw) {
            $data['raw'] = $ctx->raw;
        }

        return $data;
    }

    private function formatCompact(string $alias, GeoApiContextDTO $ctx): string
    {
        $flag      = $ctx->countryCode ? ($this->flagEmoji($ctx->countryCode) . ' ' . $ctx->countryCode) : 'n/a';
        $netBadges = [];
        if ($ctx->proxy) {
            $netBadges[] = 'Proxy';
        }
        if ($ctx->hosting) {
            $netBadges[] = 'Hosting';
        }
        $vpn      = (($ctx->proxy ?? false) || ($ctx->hosting ?? false));
        $vpnBadge = $vpn ? '⛔ VPN' : '✔️ pas de VPN';
        $net      = $netBadges ? (' • ' . implode(',', $netBadges)) : '';
        $loc      = trim(($ctx->city ?? '') . (($ctx->city && $ctx->region) ? ', ' : '') . ($ctx->region ?? ''));

        return sprintf('[%s] %s %s • %s%s • ISP: %s', $alias, $ctx->ip, $flag, $loc !== '' ? $loc : 'n/a', $net, $ctx->isp ?? 'n/a');
    }

    private function formatPretty(string $alias, GeoApiContextDTO $ctx, bool $withRaw): string
    {
        $lines   = [];
        $lines[] = sprintf('Provider: %s', $alias);
        $lines[] = sprintf('IP: %s', $ctx->ip ?? '');
        $lines[] = sprintf('Pays: %s (%s) %s', $ctx->country ?? 'n/a', $ctx->countryCode ?? 'n/a', $ctx->countryCode ? $this->flagEmoji((string) $ctx->countryCode) : '');
        $lines[] = sprintf('Ville: %s', $ctx->city ?? 'n/a');
        $lines[] = sprintf('Région: %s', $ctx->region ?? 'n/a');
        $lines[] = sprintf('Coordonnées: %s, %s', $ctx->lat !== null ? (string) $ctx->lat : 'n/a', $ctx->lon !== null ? (string) $ctx->lon : 'n/a');
        $lines[] = sprintf('ISP/ASN: %s%s', $ctx->isp ?? 'n/a', $ctx->asn ? (' (' . $ctx->asn . ')') : '');
        $lines[] = sprintf('Proxy: %s • Hosting: %s • VPN: %s', $ctx->proxy ? 'oui' : 'non', $ctx->hosting ? 'oui' : 'non', (($ctx->proxy ?? false) || ($ctx->hosting ?? false)) ? 'oui' : 'non');
        if ($withRaw) {
            $lines[] = 'Raw: ' . json_encode($ctx->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return true|array<int,string>
     */
    private function validateContext(GeoApiContextDTO $ctx): bool|array
    {
        $errors = [];
        if (!is_string($ctx->ip) || $ctx->ip === '') {
            $errors[] = 'ip manquante';
        } elseif (filter_var($ctx->ip, FILTER_VALIDATE_IP) === false) {
            $errors[] = 'ip invalide';
        }
        if ($ctx->countryCode !== null && strlen($ctx->countryCode) < 2) {
            $errors[] = 'countryCode invalide';
        }
        if ($errors) {
            return $errors;
        }

        return true;
    }

    private function fakeContext(string $ip): GeoApiContextDTO
    {
        return new GeoApiContextDTO(
            ip: $ip,
            country: 'France',
            countryCode: 'FR',
            region: 'IDF',
            city: 'Paris',
            lat: 48.8566,
            lon: 2.3522,
            isp: 'FAKE ISP',
            asn: 'AS12345',
            proxy: false,
            hosting: false,
            raw: ['simulated' => true]
        );
    }

    private function flagEmoji(string $code): string
    {
        $code = strtoupper($code);
        $out  = '';
        for ($i = 0; $i < strlen($code); ++$i) {
            $out .= mb_chr(0x1F1E6 - ord('A') + ord($code[$i]), 'UTF-8');
        }

        return $out;
    }
}
