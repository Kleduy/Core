<?php

declare(strict_types=1);

namespace Horde\Core\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Horde_Registry;
use Horde_Session;
use Horde_Exception;

/**
 * DemandGlobalAdmin middleware
 * Checks if the current session token is in the Horde-Session-Token header.
 *
 * @author    Mahdi Pasche <pasche@b1-systems.de>
 * @category  Horde
 * @copyright 2013-2021 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Core
 */
class DemandGlobalAdmin implements MiddlewareInterface
{
    protected ResponseFactoryInterface $responseFactory;
    protected StreamFactoryInterface $streamFactory;
    protected Horde_Session $session;
    protected Horde_Registry $registry;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        Horde_Session $session,
        Horde_Registry $registry
    ) {
        $this->responseFactory = $responseFactory;
        
        $this->session = $session;
        $this->registry=$registry;
    }

    /**
     * 
     * Returns 401 response if user is guest or not Admin
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Using getHeaderLine forces the request to have a single value for the header to be valid
        
        if($this->registry->isAuthenticated()&& $request->withoutAttribute('HORDE_GUEST')&& $request->withAttribute('HORDE_GLOBAL_ADMIN', true)){
            return $handler->handle($request);
        }
        else{
            return $this->responseFactory->createResponse(401, 'User is not an Admin.');
        }
        
    }
}
