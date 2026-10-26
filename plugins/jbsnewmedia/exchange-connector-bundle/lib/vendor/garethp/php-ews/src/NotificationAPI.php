<?php

namespace garethp\ews;

use garethp\ews\API\ClassMap;
use garethp\ews\API\Type\BaseFolderIdType;
use garethp\ews\API\Type\FolderIdType;

class NotificationAPI extends API
{
    public function subscribeToPushNotifications(
        BaseFolderIdType $folderId,
        array $events,
        $url,
        $frequency,
        array $options = array()
    ) {
        $request = [
            'EventTypes' => ['EventType' => $events],
            'URL' => $url,
            'StatusFrequency' => $frequency,
            'FolderIds' =>  $folderId->toArray(true)
        ];

        $request = array_replace_recursive($request, $options);

        return $this->getClient()->Subscribe([
            'PushSubscriptionRequest' => $request
        ]);
    }

    public static function handlePullNotification($uri, callable $handle, array $options = array())
    {
        $server = new \SoapServer(
            __DIR__ . '/../Resources/wsdl/notification-service.wsdl',
            [
                'uri' => $uri,
                'classmap' => ClassMap::getClassMap(),
            ]
        );
        $server->setObject(new NotificationHandler($handle));
        $server->handle();

        return $server;
    }
}
