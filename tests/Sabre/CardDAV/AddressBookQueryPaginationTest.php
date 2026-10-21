<?php

declare(strict_types=1);

namespace Sabre\CardDAV;

use PHPUnit\Framework\TestCase;
use Sabre\DAV;
use Sabre\HTTP;

class AddressBookQueryPaginationTest extends TestCase
{
    /**
     * Cards in backend order, which differs from name order.
     */
    private const CARDS = [
        'card-c' => "BEGIN:VCARD\nVERSION:3.0\nUID:c\nFN:Charlie\nEMAIL:c@example.org\nEND:VCARD",
        'card-a' => "BEGIN:VCARD\nVERSION:3.0\nUID:a\nFN:Alice\nEMAIL:a@example.org\nEND:VCARD",
        'card-e' => "BEGIN:VCARD\nVERSION:3.0\nUID:e\nFN:Eve\nEND:VCARD",
        'card-b' => "BEGIN:VCARD\nVERSION:3.0\nUID:b\nFN:Bob\nEMAIL:b@example.org\nEND:VCARD",
        'card-d' => "BEGIN:VCARD\nVERSION:3.0\nUID:d\nFN:Dave\nEMAIL:d@example.org\nEND:VCARD",
    ];

    private const EMAIL_FILTER = '<c:filter><c:prop-filter name="EMAIL" /></c:filter>';

    /**
     * @var DAV\Server
     */
    private $server;

    /**
     * @var QueryableAddressBookMock
     */
    private $queryableBook;

    /**
     * @var MultiGetQueryableAddressBookMock
     */
    private $multiGetBook;

    /**
     * @var DAV\SimpleCollection
     */
    private $root;

    public function setUp(): void
    {
        $backend = new Backend\Mock(
            [
                [
                    'id' => 'foo',
                    'uri' => 'book1',
                    'principaluri' => 'principals/user1',
                ],
            ],
            ['foo' => self::CARDS]
        );

        $this->queryableBook = new QueryableAddressBookMock('qbook', self::CARDS);
        $this->multiGetBook = new MultiGetQueryableAddressBookMock('mbook', self::CARDS);

        $this->root = new DAV\SimpleCollection('addressbooks', [
            new AddressBook($backend, $backend->addressBooks[0]),
            $this->queryableBook,
            $this->multiGetBook,
        ]);
        $this->server = new DAV\Server([$this->root]);
        $this->server->sapi = new HTTP\SapiMock();
        $this->server->addPlugin(new Plugin());
        $this->server->debugExceptions = true;
    }

    public function testFeature()
    {
        self::assertContains('sabredav-addressbook-query-continuation', (new Plugin())->getFeatures());
    }

    public function testNoLimitKeepsBackendOrder()
    {
        $response = $this->report('addressbooks/book1', '');

        self::assertSame(
            [
                ['/addressbooks/book1/card-c', null, []],
                ['/addressbooks/book1/card-a', null, []],
                ['/addressbooks/book1/card-e', null, []],
                ['/addressbooks/book1/card-b', null, []],
                ['/addressbooks/book1/card-d', null, []],
            ],
            $this->parseResponses($response)
        );
    }

    public function testLimitTruncates()
    {
        $response = $this->report('addressbooks/book1', self::EMAIL_FILTER.$this->limit(2));

        self::assertSame(
            [
                ['/addressbooks/book1/card-a', null, []],
                ['/addressbooks/book1/card-b', null, []],
                ['/addressbooks/book1/', '507', $this->truncation('card-b')],
            ],
            $this->parseResponses($response)
        );
    }

    public function testTruncatedResponseXml()
    {
        $response = $this->report('addressbooks/book1', self::EMAIL_FILTER.$this->limit(1));

        $etag = md5(self::CARDS['card-a']);
        $expected = <<<XML
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:card="urn:ietf:params:xml:ns:carddav">
 <d:response>
  <d:href>/addressbooks/book1/card-a</d:href>
  <d:propstat>
   <d:prop>
    <d:getetag>&quot;$etag&quot;</d:getetag>
   </d:prop>
   <d:status>HTTP/1.1 200 OK</d:status>
  </d:propstat>
 </d:response>
 <d:response>
  <d:href>/addressbooks/book1/</d:href>
  <d:status>HTTP/1.1 507 Insufficient Storage</d:status>
  <d:error>
   <d:number-of-matches-within-limits/>
  </d:error>
  <s:continuation-token>card-a</s:continuation-token>
 </d:response>
</d:multistatus>
XML;

        self::assertXmlStringEqualsXmlString($expected, $response->getBodyAsString());
    }

    public function testContinuationReturnsLastPage()
    {
        $response = $this->report(
            'addressbooks/book1',
            self::EMAIL_FILTER.$this->limit(2).'<s:continuation-token>card-b</s:continuation-token>'
        );

        self::assertSame(
            [
                ['/addressbooks/book1/card-c', null, []],
                ['/addressbooks/book1/card-d', null, []],
            ],
            $this->parseResponses($response)
        );
    }

    public function testPagesCoverAllMatches()
    {
        $hrefs = [];
        $token = null;
        $requests = 0;
        do {
            $body = self::EMAIL_FILTER.$this->limit(1);
            if (null !== $token) {
                $body .= '<s:continuation-token>'.$token.'</s:continuation-token>';
            }
            $responses = $this->parseResponses($this->report('addressbooks/book1', $body));
            $token = null;
            foreach ($responses as list($href, $status, $additional)) {
                if ('507' === $status) {
                    $token = $additional['{http://sabredav.org/ns}continuation-token'];
                } else {
                    $hrefs[] = $href;
                }
            }
            ++$requests;
        } while (null !== $token && $requests < 10);

        self::assertSame(
            [
                '/addressbooks/book1/card-a',
                '/addressbooks/book1/card-b',
                '/addressbooks/book1/card-c',
                '/addressbooks/book1/card-d',
            ],
            $hrefs
        );
        self::assertSame(4, $requests);
    }

    public function testLimitOrdersByName()
    {
        $response = $this->report('addressbooks/book1', $this->limit(10));

        self::assertSame(
            [
                ['/addressbooks/book1/card-a', null, []],
                ['/addressbooks/book1/card-b', null, []],
                ['/addressbooks/book1/card-c', null, []],
                ['/addressbooks/book1/card-d', null, []],
                ['/addressbooks/book1/card-e', null, []],
            ],
            $this->parseResponses($response)
        );
    }

    public function testContinuationWithoutLimit()
    {
        $response = $this->report('addressbooks/book1', '<s:continuation-token>card-b</s:continuation-token>');

        self::assertSame(
            [
                ['/addressbooks/book1/card-c', null, []],
                ['/addressbooks/book1/card-d', null, []],
                ['/addressbooks/book1/card-e', null, []],
            ],
            $this->parseResponses($response)
        );
    }

    public function testPreferMinimal()
    {
        $response = $this->report('addressbooks/book1', self::EMAIL_FILTER.$this->limit(1), ['Prefer' => 'return=minimal']);

        self::assertSame('Brief,Prefer', $response->getHeader('Vary'));
        self::assertSame(
            [
                ['/addressbooks/book1/card-a', null, []],
                ['/addressbooks/book1/', '507', $this->truncation('card-a')],
            ],
            $this->parseResponses($response)
        );
    }

    public function testStreamingMultiStatus()
    {
        $streamMultiStatus = DAV\Server::$streamMultiStatus;
        DAV\Server::$streamMultiStatus = true;
        try {
            $response = $this->report('addressbooks/book1', self::EMAIL_FILTER.$this->limit(1));
        } finally {
            DAV\Server::$streamMultiStatus = $streamMultiStatus;
        }

        self::assertSame(
            [
                ['/addressbooks/book1/card-a', null, []],
                ['/addressbooks/book1/', '507', $this->truncation('card-a')],
            ],
            $this->parseResponses($response)
        );
    }

    public function testBeforeMultiStatusReceivesTruncation()
    {
        $entries = null;
        $this->server->on('beforeMultiStatus', function ($fileProperties) use (&$entries) {
            $entries = $fileProperties;
        });

        $this->report('addressbooks/book1', self::EMAIL_FILTER.$this->limit(1));

        self::assertCount(2, $entries);
        self::assertSame('addressbooks/book1/card-a', $entries[0]['href']);
        self::assertInstanceOf(DAV\Xml\Element\Response::class, $entries[1]);
        self::assertSame(507, $entries[1]->getHttpStatus());
    }

    public function testQueryableAddressBook()
    {
        $filter = '<c:filter test="allof"><c:prop-filter name="EMAIL" /></c:filter>';
        $response = $this->report('addressbooks/qbook', $filter.$this->limit(2));

        self::assertSame(
            [
                ['/addressbooks/qbook/card-c', null, []],
                ['/addressbooks/qbook/card-a', null, []],
                ['/addressbooks/qbook/', '507', $this->truncation('after:card-a')],
            ],
            $this->parseResponses($response)
        );

        $response = $this->report('addressbooks/qbook', $filter.$this->limit(2).'<s:continuation-token>after:card-a</s:continuation-token>');

        // card-e doesn't match the filter, card-d is the last candidate.
        self::assertSame(
            [
                ['/addressbooks/qbook/card-b', null, []],
                ['/addressbooks/qbook/card-d', null, []],
            ],
            $this->parseResponses($response)
        );

        $filters = [
            [
                'name' => 'EMAIL',
                'test' => 'anyof',
                'is-not-defined' => false,
                'param-filters' => [],
                'text-matches' => [],
            ],
        ];
        self::assertSame(
            [
                ['filters' => $filters, 'test' => 'allof', 'limit' => 50, 'continuationToken' => null],
                ['filters' => $filters, 'test' => 'allof', 'limit' => 50, 'continuationToken' => 'after:card-a'],
            ],
            $this->queryableBook->calls
        );
    }

    public function testQueryableAddressBookWithoutLimit()
    {
        $response = $this->report('addressbooks/qbook', '<s:continuation-token>after:card-a</s:continuation-token>');

        self::assertSame(
            [
                ['/addressbooks/qbook/card-e', null, []],
                ['/addressbooks/qbook/card-b', null, []],
                ['/addressbooks/qbook/card-d', null, []],
            ],
            $this->parseResponses($response)
        );
        self::assertSame(
            [['filters' => [], 'test' => 'anyof', 'limit' => null, 'continuationToken' => 'after:card-a']],
            $this->queryableBook->calls
        );
    }

    public function testQueryableAddressBookWithoutFiltersRequestsRemainingMatches()
    {
        $this->queryableBook->candidates = ['card-b', 'deleted', 'card-a', 'card-c'];

        $response = $this->report('addressbooks/qbook', $this->limit(3));

        // The missing card is skipped, the batch is exhausted and the last
        // candidate completes the page, so the result is not truncated.
        self::assertSame(
            [
                ['/addressbooks/qbook/card-b', null, []],
                ['/addressbooks/qbook/card-a', null, []],
                ['/addressbooks/qbook/card-c', null, []],
            ],
            $this->parseResponses($response)
        );
        self::assertSame([3, 1], array_column($this->queryableBook->calls, 'limit'));
        self::assertSame([null, 'after:card-a'], array_column($this->queryableBook->calls, 'continuationToken'));
    }

    public function testQueryableAddressBookMultiGet()
    {
        $this->multiGetBook->candidates = ['card-a', 'deleted', 'card-e', 'card-b', 'card-d'];

        $response = $this->report('addressbooks/mbook', self::EMAIL_FILTER.$this->limit(2));

        self::assertSame(
            [
                ['/addressbooks/mbook/card-a', null, []],
                ['/addressbooks/mbook/card-b', null, []],
                ['/addressbooks/mbook/', '507', $this->truncation('after:card-b')],
            ],
            $this->parseResponses($response)
        );
        self::assertSame([['card-a', 'deleted', 'card-e', 'card-b', 'card-d']], $this->multiGetBook->multiGetCalls);
    }

    public function testQueryableAddressBookFillsPageAcrossBatches()
    {
        $book = $this->addSparseBook('sparse', 130, [20, 70, 120]);

        $response = $this->report('addressbooks/sparse', self::EMAIL_FILTER.$this->limit(3));

        self::assertSame(
            [
                ['/addressbooks/sparse/card-020', null, []],
                ['/addressbooks/sparse/card-070', null, []],
                ['/addressbooks/sparse/card-120', null, []],
                ['/addressbooks/sparse/', '507', $this->truncation('after:card-120')],
            ],
            $this->parseResponses($response)
        );
        self::assertSame([50, 50, 50], array_column($book->calls, 'limit'));
        self::assertSame([null, 'after:card-049', 'after:card-099'], array_column($book->calls, 'continuationToken'));

        $response = $this->report('addressbooks/sparse', self::EMAIL_FILTER.$this->limit(3).'<s:continuation-token>after:card-120</s:continuation-token>');

        self::assertSame([], $this->parseResponses($response));
    }

    public function testQueryableAddressBookScanBudget()
    {
        $book = $this->addSparseBook('sparse', 600, [550]);

        $response = $this->report('addressbooks/sparse', self::EMAIL_FILTER.$this->limit(1));

        self::assertSame(
            [
                ['/addressbooks/sparse/', '507', $this->truncation('after:card-499')],
            ],
            $this->parseResponses($response)
        );
        self::assertCount(10, $book->calls);

        $response = $this->report('addressbooks/sparse', self::EMAIL_FILTER.$this->limit(1).'<s:continuation-token>after:card-499</s:continuation-token>');

        self::assertSame(
            [
                ['/addressbooks/sparse/card-550', null, []],
                ['/addressbooks/sparse/', '507', $this->truncation('after:card-550')],
            ],
            $this->parseResponses($response)
        );
    }

    public function testQueryableAddressBookPagesCoverAllMatches()
    {
        $this->addSparseBook('sparse', 600, [0, 1, 2, 3, 250, 251, 599]);

        foreach ([1, 2, 3, 7, 8] as $limit) {
            list($hrefs) = $this->walkPages('addressbooks/sparse', self::EMAIL_FILTER, $limit);
            self::assertSame(
                [
                    '/addressbooks/sparse/card-000',
                    '/addressbooks/sparse/card-001',
                    '/addressbooks/sparse/card-002',
                    '/addressbooks/sparse/card-003',
                    '/addressbooks/sparse/card-250',
                    '/addressbooks/sparse/card-251',
                    '/addressbooks/sparse/card-599',
                ],
                $hrefs,
                'limit '.$limit
            );
        }

        list($hrefs, $requests) = $this->walkPages('addressbooks/qbook', self::EMAIL_FILTER, 1);
        self::assertSame(
            [
                '/addressbooks/qbook/card-c',
                '/addressbooks/qbook/card-a',
                '/addressbooks/qbook/card-b',
                '/addressbooks/qbook/card-d',
            ],
            $hrefs
        );
        // The last candidate completes the 4th page, so there is no 5th request.
        self::assertSame(4, $requests);
    }

    public function testQueryableAddressBookInvalidToken()
    {
        $response = $this->report('addressbooks/qbook', $this->limit(3).'<s:continuation-token>bogus</s:continuation-token>');

        self::assertSame(400, $response->getStatus());
        self::assertSame('bogus', $this->queryableBook->calls[0]['continuationToken']);
    }

    /**
     * Adds a queryable address book with $count cards, where only the cards at
     * the indexes in $matches have an EMAIL property.
     */
    private function addSparseBook(string $name, int $count, array $matches): QueryableAddressBookMock
    {
        $cards = [];
        for ($i = 0; $i < $count; ++$i) {
            $cards[sprintf('card-%03d', $i)] = "BEGIN:VCARD\nVERSION:3.0\nUID:$i\nFN:Card $i\n"
                .(in_array($i, $matches, true) ? "EMAIL:$i@example.org\n" : '')
                .'END:VCARD';
        }
        $book = new QueryableAddressBookMock($name, $cards);
        $this->root->addChild($book);

        return $book;
    }

    /**
     * Requests all pages of a query, following the continuation tokens.
     *
     * @return array{0: string[], 1: int} the returned hrefs and the number of requests
     */
    private function walkPages(string $uri, string $query, int $limit): array
    {
        $hrefs = [];
        $token = null;
        $requests = 0;
        do {
            $body = $query.$this->limit($limit);
            if (null !== $token) {
                $body .= '<s:continuation-token>'.$token.'</s:continuation-token>';
            }
            $responses = $this->parseResponses($this->report($uri, $body));
            $token = null;
            foreach ($responses as list($href, $status, $additional)) {
                if ('507' === $status) {
                    $token = $additional['{http://sabredav.org/ns}continuation-token'];
                } else {
                    $hrefs[] = $href;
                }
            }
            ++$requests;
        } while (null !== $token && $requests < 100);

        return [$hrefs, $requests];
    }

    private function limit(int $nresults): string
    {
        return '<c:limit><c:nresults>'.$nresults.'</c:nresults></c:limit>';
    }

    /**
     * @return array<string, mixed>
     */
    private function truncation(string $token): array
    {
        return [
            '{DAV:}error' => ['{DAV:}number-of-matches-within-limits' => null],
            '{http://sabredav.org/ns}continuation-token' => $token,
        ];
    }

    private function report(string $uri, string $query, array $headers = []): HTTP\Response
    {
        $request = new HTTP\Request('REPORT', '/'.$uri, ['Depth' => '1'] + $headers);
        $request->setBody(
            '<?xml version="1.0"?>
<c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav" xmlns:s="http://sabredav.org/ns">
    <d:prop>
      <d:getetag />
    </d:prop>
    '.$query.'
</c:addressbook-query>'
        );

        $response = new HTTP\Response();
        $this->server->httpRequest = $request;
        $this->server->httpResponse = $response;
        $this->server->exec();

        return $response;
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: array<string, mixed>}> href, response status and additional elements of every response
     */
    private function parseResponses(HTTP\Response $response): array
    {
        $body = $response->getBodyAsString();
        self::assertSame(207, $response->getStatus(), 'Incorrect status code. Full response body:'.$body);

        $result = [];
        foreach ($this->server->xml->expect('{DAV:}multistatus', $body)->getResponses() as $item) {
            $result[] = [$item->getHref(), $item->getHttpStatus(), $item->getAdditionalElements()];
        }

        return $result;
    }
}
