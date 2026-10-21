<?php

declare(strict_types=1);

namespace Sabre\CardDAV;

/**
 * Queryable AddressBook interface.
 *
 * Address books that can evaluate addressbook-query filters and paginate in
 * their backend.
 *
 * If an address book implements this interface, the CardDAV plugin uses it to
 * answer addressbook-query REPORTs instead of loading and filtering every card
 * in the collection.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface IQueryableAddressBook extends IAddressBook
{
    /**
     * Returns candidate child cards for $filters in a stable order, starting after
     * $continuationToken (null = from the beginning).
     *
     * Implementations MAY return cards that don't match the filters (the plugin re-validates every
     * returned card) but MUST NOT omit a matching card. $filters / $test have the exact structure of
     * Xml\Request\AddressBookQueryReport::$filters / ::$test.
     *
     * Every returned card carries the token that continues directly after it, so the caller can stop
     * at any card of the batch. hasMore tells whether candidates exist after the last returned card.
     *
     * @param int|null    $limit             maximum number of cards to return, null for no limit
     * @param string|null $continuationToken token of the card to continue after
     *
     * @return array{cards: list<array{name: string, continuationToken: string}>, hasMore: bool}
     *
     * @throws \Sabre\DAV\Exception\BadRequest when the token is invalid
     */
    public function addressBookQuery(array $filters, string $test, ?int $limit, ?string $continuationToken): array;
}
