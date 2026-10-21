<?php

declare(strict_types=1);

namespace Sabre\CardDAV;

use Sabre\DAV;

/**
 * Queryable address book supporting multi-get.
 *
 * getMultipleChildren() returns the existing children in reverse order and
 * records every call in $multiGetCalls.
 */
class MultiGetQueryableAddressBookMock extends QueryableAddressBookMock implements DAV\IMultiGet
{
    /**
     * @var list<string[]>
     */
    public $multiGetCalls = [];

    public function getMultipleChildren(array $paths)
    {
        $this->multiGetCalls[] = $paths;

        $children = [];
        foreach (array_reverse($paths) as $path) {
            if (isset($this->children[$path])) {
                $children[] = $this->children[$path];
            }
        }

        return $children;
    }
}
