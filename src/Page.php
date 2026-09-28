<?php

declare(strict_types=1);

namespace WebhookAdmin;

/**
 * One page of a list.
 *
 * `items` and `nextCursor` are this page. Iterating the page with `foreach` walks every item across pages,
 * fetching the next page when needed.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate
{
    /** @var list<T> */
    public readonly array $items;
    /** Pass as `cursor` to get the next page. Null on the last page. */
    public readonly ?string $nextCursor;

    /**
     * @param array{items?: array<T>|null, next_cursor?: string|null} $data
     * @param \Closure(string): array{items?: array<T>|null, next_cursor?: string|null} $fetch
     */
    public function __construct(array $data, private readonly \Closure $fetch)
    {
        $this->items = array_values($data['items'] ?? []);
        $cursor = $data['next_cursor'] ?? null;
        $this->nextCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function hasNextPage(): bool
    {
        return $this->nextCursor !== null;
    }

    /** @return Page<T>|null The next page, or null on the last page. */
    public function nextPage(): ?Page
    {
        if ($this->nextCursor === null) {
            return null;
        }
        return new Page(($this->fetch)($this->nextCursor), $this->fetch);
    }

    /** @return \Generator<int, Page<T>> */
    public function pages(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            yield $page;
            $page = $page->nextPage();
        }
    }

    /** @return \Generator<int, T> */
    public function getIterator(): \Generator
    {
        foreach ($this->pages() as $page) {
            foreach ($page->items as $item) {
                yield $item;
            }
        }
    }
}
