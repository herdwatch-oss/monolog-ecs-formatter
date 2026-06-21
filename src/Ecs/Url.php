<?php

declare(strict_types=1);

namespace Herdwatch\MonologEcsFormatter\Ecs;

/**
 * ECS `url.*` fields — the parts of a request URL, logged as first-class fields. Immutable;
 * construct with named arguments, or use {@see Url::parse()} to split a URL string. Null fields
 * are omitted. `query` is stored without its leading `?` (the ECS convention).
 *
 * Example:
 *   new Url(path: '/herds/42', domain: 'app.herdwatch.com', scheme: 'https', query: 'view=summary');
 *   Url::parse('https://app.herdwatch.com/herds/42?view=summary');
 *
 * @see https://www.elastic.co/guide/en/ecs/current/ecs-url.html
 */
final class Url implements EcsField
{
    public function __construct(
        private readonly ?string $path = null,
        private readonly ?string $domain = null,
        private readonly ?string $scheme = null,
        private readonly ?string $query = null,
        private readonly ?int $port = null,
        private readonly ?string $full = null,
        private readonly ?string $fragment = null,
    ) {
    }

    /**
     * Split a URL string into its ECS url.* parts (scheme, domain, port, path, query, fragment),
     * keeping the original string as url.full. Parts the URL omits are left null. A string that
     * cannot be parsed is still recorded verbatim as url.full rather than discarded.
     */
    public static function parse(string $url): self
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return new self(full: $url);
        }

        return new self(
            path: $parts['path'] ?? null,
            domain: $parts['host'] ?? null,
            scheme: $parts['scheme'] ?? null,
            query: $parts['query'] ?? null,
            port: $parts['port'] ?? null,
            full: $url,
            fragment: $parts['fragment'] ?? null,
        );
    }

    public function toEcs(): array
    {
        $url = array_filter(
            [
                'full' => $this->full,
                'scheme' => $this->scheme,
                'domain' => $this->domain,
                'port' => $this->port,
                'path' => $this->path,
                'query' => $this->query,
                'fragment' => $this->fragment,
            ],
            static fn (string|int|null $value): bool => $value !== null,
        );

        return $url === [] ? [] : ['url' => $url];
    }
}
