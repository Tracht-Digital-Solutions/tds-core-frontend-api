<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\CacheResult;
use Tds\Frontend\Contract\ConnectedSiteCache;
use Tds\Frontend\Contract\ReportingSiteCache;

final class ConnectedSiteCacheService implements ConnectedSiteCache
{
    public function __construct(
        private readonly SiteConnectionStore $connections,
        private readonly ReportingSiteCache $cache,
    ) {
    }

    public function refresh(string $resourceType, string $resourceId, CacheEvent $event): CacheResult
    {
        try {
            $target = $this->connections->cacheTarget($resourceType, $resourceId);
            if ($target === null) {
                return new CacheResult(CacheResult::NOT_CONFIGURED);
            }
            return $this->cache->rebuildWithResult($target['origin'], $target['token'], [$event]);
        } catch (\Throwable) {
            // Cache refresh is always a second outcome beside persistence. A
            // remote/transport bug must be reportable without rolling the
            // content write back.
            return new CacheResult(CacheResult::FAILED, failed: [['error' => 'cache_refresh_failed']]);
        }
    }
}
