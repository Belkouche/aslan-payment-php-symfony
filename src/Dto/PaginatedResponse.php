<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Generic paginated response wrapper.
 *
 * The $data property holds an array of typed DTO instances. The concrete
 * resource class is responsible for hydrating the individual items before
 * wrapping them in this object.
 *
 * @template T
 */
final readonly class PaginatedResponse
{
    /**
     * @param  array<T> $data       Typed DTO items for the current page
     * @param  int      $page       Current page (1-based)
     * @param  int      $pageSize   Number of items requested per page
     * @param  int      $total      Total items across all pages
     * @param  int      $totalPages Total number of pages
     */
    public function __construct(
        public array $data,
        public int $page,
        public int $pageSize,
        public int $total,
        public int $totalPages,
    ) {}

    /**
     * Build from a raw API response envelope.
     *
     * @param  array<string, mixed>  $raw      Decoded API response
     * @param  callable(array): T   $hydrator Converts each raw item to a DTO
     * @return self<T>
     */
    public static function fromArray(array $raw, callable $hydrator): self
    {
        $items = array_map($hydrator, $raw['data'] ?? []);

        $meta = $raw['meta'] ?? $raw['pagination'] ?? [];

        $pageSize = (int) ($meta['pageSize']    ?? $meta['perPage']     ?? count($items));
        $total    = (int) ($meta['total']       ?? $meta['totalItems']  ?? count($items));

        return new self(
            data:       $items,
            page:       (int) ($meta['page']       ?? $meta['currentPage'] ?? 1),
            pageSize:   $pageSize,
            total:      $total,
            // Some endpoints (e.g. billing) return only total + pageSize.
            totalPages: (int) ($meta['totalPages']  ?? $meta['lastPage']
                ?? ($pageSize > 0 ? max(1, (int) ceil($total / $pageSize)) : 1)),
        );
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages;
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }
}
