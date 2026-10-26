<?php

namespace garethp\ews;

use garethp\ews\API\Enumeration\SubscriptionStatusType;
use garethp\ews\API\Exception;
use garethp\ews\API\ExchangeWebServices;
use garethp\ews\API\Message\SendNotificationResponseMessageType;
use garethp\ews\API\Message\SendNotificationResponseType;
use garethp\ews\API\Message\SendNotificationResultType;

class NotificationHandler
{
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    // @codingStandardsIgnoreLine
    public function SendNotification(SendNotificationResponseType $message)
    {
        $message = ExchangeWebServices::drillDownResponseLevels($message);

        $handler = $this->handler;
        $response = $handler($message);

        if ($response !== SubscriptionStatusType::OK && $response !== SubscriptionStatusType::UNSUBSCRIBE) {
            throw new Exception('Unexpected Subscription Status Response');
        }

        $result = new SendNotificationResultType();
        $result->setSubscriptionStatus($response);

        return $result->toXmlObject();
    }
}
