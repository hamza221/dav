<?php

namespace Sabre\CardDAV;

use Sabre\DAV;
use Sabre\VObject;

/**
 * VCF Exporter
 *
 * This plugin adds the ability to export entire address books as .vcf files.
 * This is useful for clients that don't support CardDAV yet. They often do
 * support vcf files.
 *
 * @copyright Copyright (C) 2007-2013 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @author Thomas Tanghus (http://tanghus.net/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class VCFExportPlugin extends DAV\ServerPlugin {

    /**
     * Reference to Server class
     *
     * @var Sabre\DAV\Server
     */
    protected $server;

    /**
     * Initializes the plugin and registers event handlers
     *
     * @param DAV\Server $server
     * @return void
     */
    public function initialize(DAV\Server $server) {

        $this->server = $server;
        $this->server->on('beforeMethod',array($this,'beforeMethod'), 90);

    }

    /**
     * 'beforeMethod' event handles. This event handles intercepts GET requests ending
     * with ?export
     *
     * @param string $method
     * @param string $uri
     * @return bool
     */
    public function beforeMethod($method, $uri) {

        if ($method!='GET') return;

        $queryParams = $this->server->httpRequest->getQueryParameters();
        if (!array_key_exists('export', $queryParams)) return;

        // splitting uri
        list($uri) = explode('?',$uri,2);

        $node = $this->server->tree->getNodeForPath($uri);

        if (!($node instanceof IAddressBook)) return;

        $this->server->transactionType = 'get-addressbook-export';

        // Checking ACL, if available.
        if ($aclPlugin = $this->server->getPlugin('acl')) {
            $aclPlugin->checkPrivileges($uri, '{DAV:}read');
        }

        $this->server->httpResponse->setHeader('Content-Type','text/directory');
        $this->server->httpResponse->setStatus(200);

        $nodes = $this->server->getPropertiesForPath($uri, array(
            '{' . Plugin::NS_CARDDAV . '}address-data',
        ),1);

        $this->server->httpResponse->setBody($this->generateVCF($nodes));
        $this->server->httpResponse->send();

        // Returning false to break the event chain
        return false;

    }

    /**
     * Merges all vcard objects, and builds one big vcf export
     *
     * @param array $nodes
     * @return string
     */
    public function generateVCF(array $nodes) {

        $output = "";

        foreach($nodes as $node) {

            if (!isset($node[200]['{' . Plugin::NS_CARDDAV . '}address-data'])) {
                continue;
            }
            $nodeData = $node[200]['{' . Plugin::NS_CARDDAV . '}address-data'];

            // Parsing this node so VObject can clean up the output.
            $output .=
               VObject\Reader::read($nodeData)->serialize();

        }

        return $output;

    }

}
