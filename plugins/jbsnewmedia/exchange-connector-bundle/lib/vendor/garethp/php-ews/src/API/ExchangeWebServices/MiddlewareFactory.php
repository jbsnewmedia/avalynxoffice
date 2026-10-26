<?php

namespace garethp\ews\API\ExchangeWebServices;

use garethp\ews\API\MiddlewareRequest;
use garethp\ews\API\MiddlewareResponse;
use garethp\ews\API\Type;
use garethp\ews\API\ExchangeWebServices;
use garethp\ews\API\Type\FindFolderParentType;
use garethp\ews\API\Type\FindItemParentType;

class MiddlewareFactory
{
    public function getSoapCall()
    {
        return function (MiddlewareRequest $request) {
            $client = $this->getClient();
            $response = $client->__call($request->getName(), $request->getArguments());
            $response = MiddlewareResponse::newResponse($response);

            return $response;
        };
    }

    public function getTypeToXMLObject()
    {
        return function (MiddlewareRequest $request, callable $next) {
            if ($request->getRequest() instanceof Type) {
                $request->setRequest($request->getRequest()->toXmlObject());
            }

            return $next($request);
        };
    }

    public function getStripSyncScopeForExchange2007()
    {
        return function (MiddlewareRequest $request, callable $next) {
            $requestObj = $request->getRequest();

            if ($request->getName() == "SyncFolderItems"
                && $request->getOptions()['version'] < ExchangeWebServices::VERSION_2010
                && isset($requestObj->SyncScope)) {
                unset($requestObj->SyncScope);
                $request->setRequest($requestObj);
            }

            return $next($request);
        };
    }

    public function getProcessResponse()
    {
        return function (MiddlewareRequest $request, callable $next) {
            $response = $next($request);

            $response->setResponse($this->processResponse($response->getResponse()));

            return $response;
        };
    }

    public function getAddLastRequestToPagedResults()
    {
        return function (MiddlewareRequest $request, callable $next) {
            $response = $next($request);

            $responseObject = $response->getResponse();
            if (($responseObject instanceof FindItemParentType
                    || $responseObject instanceof FindFolderParentType)
                && !$responseObject->isIncludesLastItemInRange()) {
                $responseObject->setLastRequest($request->getRequest());
                $response->setResponse($responseObject);
            }

            return $response;
        };
    }
}
