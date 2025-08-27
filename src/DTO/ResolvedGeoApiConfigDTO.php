<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\DTO;

class ResolvedGeoApiConfigDTO
{
    public const REQUEST_ATTR_KEY = '_geolocator_effective_config';

    /**
     * @param array{default?: string, list?: array<string>}                                                  $providers
     * @param array{dsn: string|null}                                                                        $storage
     * @param array{max_attempts:int, ttl:int, ban_duration:string}                                          $bans
     * @param array<string, mixed>                                                                           $filters
     * @param array{headers?: array<int, string>, proxies?: array<int, string>, routes?: array<int, string>} $trusted
     * @param array{key_strategy?: 'ip'|'session'}                                                           $exclusions
     */
    public function __construct(
        // Root (same keys as YAML)
        public bool $enabled = true,
        public ?string $eventBridgeService = null,
        public bool $providerFallbackMode = false,
        public ?string $redirectOnBan = null,
        public string $logChannel = 'geolocator',
        public string $logLevel = 'warning',
        public bool $simulate = false,

        // Cache strategy/overrides
        public string $cacheKeyStrategy = 'ip',

        // Sélection et tuning fournisseurs (overrides par attributs)
        public ?string $provider = null,
        public ?string $forceProvider = null,
        public ?int $cacheTtl = null,
        public bool $blockOnError = true,
        public ?string $exclusionKey = null,

        // Fournisseurs (structure du YAML)
        public array $providers = [
            'default' => 'findip',
            'list'    => [],
        ],

        // Sous-objets
        public array $storage = [
            'dsn' => null,
        ],
        public array $bans = [
            'max_attempts' => 10,
            'ttl'          => 3600,
            'ban_duration' => '1 hour',
        ],
        public array $filters = [
            // Example of expected structure; keys are flexible
            // 'navigator' => ['enabled' => true, 'default_behavior' => 'allow', 'rules' => []],
            // 'country'   => ['default_behavior' => 'allow', 'rules' => []],
            // 'ip'        => ['default_behavior' => 'allow', 'rules' => []],
            // 'crawler'   => ['enabled' => true, 'allow_known' => true, 'default_behavior' => 'allow', 'rules' => []],
        ],
        public array $trusted = [
            'headers' => [],
            'proxies' => [],
            'routes'  => [],
        ],
        public array $exclusions = [
            'key_strategy' => 'ip',
        ],
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getEventBridgeService(): ?string
    {
        return $this->eventBridgeService;
    }

    public function isProviderFallbackMode(): bool
    {
        return $this->providerFallbackMode;
    }

    public function getRedirectOnBan(): ?string
    {
        return $this->redirectOnBan;
    }

    public function getLogChannel(): string
    {
        return $this->logChannel;
    }

    public function getLogLevel(): string
    {
        return $this->logLevel;
    }

    public function isSimulate(): bool
    {
        return $this->simulate;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getForceProvider(): ?string
    {
        return $this->forceProvider;
    }

    public function getCacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    public function getCacheKeyStrategy(): string
    {
        return $this->cacheKeyStrategy ?? 'ip';
    }

    public function isBlockOnError(): bool
    {
        return $this->blockOnError;
    }

    public function getExclusionKey(): ?string
    {
        return $this->exclusionKey;
    }

    /**
     * @return array{default?: string, list?: array<string>}
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * @return array{dsn: string|null}
     */
    public function getStorage(): array
    {
        return $this->storage;
    }

    /**
     * @return array{max_attempts:int, ttl:int, ban_duration:string}
     */
    public function getBans(): array
    {
        return $this->bans;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return array{headers?: array<int, string>, proxies?: array<int, string>, routes?: array<int, string>}
     */
    public function getTrusted(): array
    {
        return $this->trusted;
    }

    /**
     * @return array{key_strategy?: 'ip'|'session'}
     */
    public function getExclusions(): array
    {
        return $this->exclusions;
    }

    public function getExclusionsKeyStrategy(): string
    {
        $v = $this->exclusions['key_strategy'] ?? 'ip';

        return in_array($v, ['ip', 'session'], true) ? $v : 'ip';
    }

    /**
     * Alias du provider effectivement utilisé par la résolution.
     */
    /**
     * @param array<string, mixed> $availableProviders
     */
    public function getSelectedProviderAlias(array $availableProviders = []): ?string
    {
        if ($this->forceProvider) {
            return $this->forceProvider;
        }
        if ($this->provider) {
            return $this->provider;
        }
        if (isset($this->providers['default'])) {
            return (string) $this->providers['default'];
        }
        // fallback: first configured provider if available
        if ($availableProviders) {
            $first = array_key_first($availableProviders);

            return (string) $first;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Renvoie strictement la même structure et les mêmes noms de clés que config.yaml
        return [
            'enabled'                => $this->enabled,
            'event_bridge_service'   => $this->eventBridgeService,
            'provider_fallback_mode' => $this->providerFallbackMode,
            'redirect_on_ban'        => $this->redirectOnBan,
            'log_channel'            => $this->logChannel,
            'log_level'              => $this->logLevel,
            'simulate'               => $this->simulate,

            // Top-level provider overrides (snake_case like YAML)
            'provider'       => $this->provider,
            'force_provider' => $this->forceProvider,
            'cache_ttl'      => $this->cacheTtl,
            'block_on_error' => $this->blockOnError,
            'exclusion_key'  => $this->exclusionKey,

            'providers' => [
                'default' => $this->providers['default'] ?? 'findip',
                'list'    => $this->providers['list']    ?? [],
            ],
            'storage' => [
                'dsn' => $this->storage['dsn'] ?? null,
            ],
            'bans' => [
                'max_attempts' => (int) ($this->bans['max_attempts'] ?? 10),
                'ttl'          => (int) ($this->bans['ttl'] ?? 3600),
                'ban_duration' => (string) ($this->bans['ban_duration'] ?? '1 hour'),
            ],
            'filters' => $this->filters,
            'trusted' => [
                'headers' => array_values($this->trusted['headers'] ?? []),
                'proxies' => array_values($this->trusted['proxies'] ?? []),
                'routes'  => array_values($this->trusted['routes'] ?? []),
            ],
            'exclusions' => [
                'key_strategy' => in_array($this->exclusions['key_strategy'] ?? 'ip', ['ip', 'session'], true) ? $this->exclusions['key_strategy'] : 'ip',
            ],
        ];
    }
}
