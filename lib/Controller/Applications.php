<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2010-13 Daniel Garner
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Xibo\Controller;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Util\RedirectUri;
use League\OAuth2\Server\Util\SecureKey;
use Xibo\Factory\ApplicationFactory;
use Xibo\Helper\Help;
use Xibo\Helper\Log;
use Xibo\Helper\Sanitize;
use Xibo\Storage\ApiAccessTokenStorage;
use Xibo\Storage\ApiAuthCodeStorage;
use Xibo\Storage\ApiClientStorage;
use Xibo\Storage\ApiScopeStorage;
use Xibo\Storage\ApiSessionStorage;
use Xibo\Storage\PDOConnect;


class Applications extends Base
{
    /**
     * Display Page
     */
    public function displayPage()
    {
        $this->getState()->template = 'applications-page';
    }

    /**
     * Display page grid
     */
    public function grid()
    {
        $this->getState()->template = 'grid';
        $this->getState()->setData(ApplicationFactory::query($this->gridRenderSort(), $this->gridRenderFilter()));
        $this->getState()->recordsTotal = ApplicationFactory::countLast();
    }

    /**
     * Display the Authorize form.
     */
    public function authorizeRequest()
    {
        // Pull authorize params from our session
        $authParams = $this->getSession()->get('authParams');

        // Get, show page
        $this->getState()->template = 'applications-authorize-page';
        $this->getState()->setData([
            'authParams' => $authParams
        ]);
    }

    /**
     * Authorize an oAuth request
     * @throws \League\OAuth2\Server\Exception\InvalidGrantException
     */
    public function authorize()
    {
        // Pull authorize params from our session
        $authParams = $this->getSession()->get('authParams');

        // We are authorized
        if (Sanitize::getString('authorization') === 'Approve') {

            // Create a server
            $server = new AuthorizationServer();

            $server->setSessionStorage(new ApiSessionStorage());
            $server->setAccessTokenStorage(new ApiAccessTokenStorage());
            $server->setClientStorage(new ApiClientStorage());
            $server->setScopeStorage(new ApiScopeStorage());
            $server->setAuthCodeStorage(new ApiAuthCodeStorage());

            $authCodeGrant = new AuthCodeGrant();
            $server->addGrantType($authCodeGrant);

            // Authorize the request
            $redirectUri = $server->getGrantType('authorization_code')->newAuthorizeRequest('user', $this->getUser()->userId, $authParams);
        }
        else {
            $error = new \League\OAuth2\Server\Exception\AccessDeniedException();
            $error->redirectUri = $authParams['redirect_uri'];

            $redirectUri = RedirectUri::make($authParams['redirect_uri'], [
                'error' => $error->errorType,
                'message' => $error->getMessage()
            ]);
        }

        Log::debug('Redirect URL is %s', $redirectUri);

        $this->getApp()->redirect($redirectUri, 302);
    }

    /**
     * Form to register a new application.
     */
    public function addForm()
    {
        $this->getState()->template = 'applications-form-add';
        $this->getState()->setData([
            'help' => Help::Link('Services', 'Register')
        ]);
    }

    /**
     * Register a new application with OAuth
     */
    public function add()
    {
        // Make and ID/Secret
        $id = SecureKey::generate();
        $secret = SecureKey::generate(254);

        // Simple Insert for now
        PDOConnect::insert('
            INSERT INTO `oauth_clients` (`id`, `secret`, `name`)
              VALUES (:id, :secret, :name)
        ', [
            'id' => $id,
            'secret' => $secret,
            'name' => Sanitize::getString('name')
        ]);

        // Update the URI
        PDOConnect::insert('INSERT INTO `oauth_client_redirect_uris` (client_id, redirect_uri) VALUES (:clientId, :redirectUri)', [
            'clientId' => $id,
            'redirectUri' => Sanitize::getString('redirectUri')
        ]);

        // Return
        $this->getState()->hydrate([
            'message' => sprintf(__('Added %s'), Sanitize::getString('name')),
            'id' => $id
        ]);
    }
}

?>