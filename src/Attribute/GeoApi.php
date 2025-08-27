<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Attribute;

use Attribute;

/**
 * PHP 8 Attribute to configure Geolocator behavior per controller or action.
 *
 * Mirrors configuration keys merged by GeoConfigCollectListener and
 * parsed via Service\Config\GeoAttributeResolver.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class GeoApi
{
    /**
     * @param string[] $excludeRoutes Route exclusion patterns
     * @param string[] $countries     Country rules shorthand (e.g. ['+FR','-BE'])
     * @param string[] $ips           IP rules shorthand (e.g. ['+127.0.0.1','-1.2.3.4'])
     * @param string[] $crawlers      Crawler rules shorthand (e.g. ['+googlebot','-curl'])
     * @param array<string,array{
     *     enabled?:bool,
     *     default_behavior?:string,
     *     allow_known?:bool,
     *     rules?:array<int,string>
     * }>|null $filters
     */
    public function __construct(
        public ?bool $enabled = null,
        public ?bool $simulate = null,
        public ?string $redirectOnBan = null,
        public ?bool $providerFallbackMode = null,

        // Provider tuning / cache
        public ?string $forceProvider = null,
        public ?int $cacheTtl = null,
        public ?bool $blockOnError = null,
        public ?string $exclusionKey = null,
        public ?string $cacheKeyStrategy = null, // 'ip' or 'session'

        // Route exclusions (patterns)
        /** @var string[] */
        public ?array $excludeRoutes = [],

        // Shortcut filters (merged into filters.*.rules)
        /** @var string[] */
        public ?array $countries = [],
        /** @var string[] */
        public ?array $ips = [],
        /** @var string[] */
        public ?array $crawlers = [],

        // VPN shorthand controls
        public ?bool $vpnEnabled = null,
        public ?string $vpnDefaultBehavior = null, // 'allow' | 'block'

        // Full filters structure override/merge
        /** @var array<string, array{enabled?:bool,default_behavior?:string,allow_known?:bool,rules?:array<int,string>}>|null */
        public ?array $filters = null,
    ) {
    }
}
