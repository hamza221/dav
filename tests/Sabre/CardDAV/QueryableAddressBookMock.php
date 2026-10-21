<?php

declare(strict_types=1);

namespace Sabre\CardDAV;

use Sabre\DAV;

/**
 * Address book answering addressbook-query with all names of $candidates,
 * without evaluating the filters.
 *
 * The continuation token of a card is 'after:' followed by its name; every
 * call is recorded in $calls.
 */
class QueryableAddressBookMock extends DAV\SimpleCollection implements IQueryableAddressBook
{
    /**
     * Candidate names in query order. May contain names without a child.
     *
     * @var string[]
     */
    public $candidates;

    /**
     * @var list<array{filters: array, test: string, limit: int|null, continuationToken: string|null}>
     */
    public $calls = [];

    /**
     * @param array<string, string> $cards card data keyed by card name
     */
    public function __construct(string $name, array $cards)
    {
        $children = [];
        foreach ($cards as $uri => $data) {
            $children[] = new Card(
                new Backend\Mock(),
                ['id' => $name, 'uri' => $name, 'principaluri' => 'principals/user1'],
                ['uri' => $uri, 'carddata' => $data]
            );
        }
        parent::__construct($name, $children);
        $this->candidates = array_keys($cards);
    }

    public function addressBookQuery(array $filters, string $test, ?int $limit, ?string $continuationToken): array
    {
        $this->calls[] = [
            'filters' => $filters,
            'test' => $test,
            'limit' => $limit,
            'continuationToken' => $continuationToken,
        ];

        $offset = 0;
        if (null !== $continuationToken) {
            $position = 0 === strpos($continuationToken, 'after:')
                ? array_search(substr($continuationToken, 6), $this->candidates, true)
                : false;
            if (false === $position) {
                throw new DAV\Exception\BadRequest('Invalid continuation token');
            }
            $offset = $position + 1;
        }

        $cards = [];
        foreach (array_slice($this->candidates, $offset, $limit) as $name) {
            $cards[] = ['name' => $name, 'continuationToken' => 'after:'.$name];
        }

        return [
            'cards' => $cards,
            'hasMore' => $offset + count($cards) < count($this->candidates),
        ];
    }
}
