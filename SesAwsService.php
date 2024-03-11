<?php

declare(strict_types=1);

namespace MauticPlugin\AivieAwsBundle\Service;

use Aws\Result;
use Aws\Ses\SesClient;

class SesAwsService extends AwsService
{
    /**
     * "setIdentityNotificationTopic" is in the aws-sdk SES, but not in SESv2.
     * An equivalent action in V2 wasn't found.
     *
     * Sets an Amazon Simple Notification Service (Amazon SNS) topic to use when delivering notifications.
     *
     * Parameters:
     *  - $identity - a verified identity, such as an email address or domain
     *  - $notificationType - the type of notifications that will be published: bounce, complaint, or delivery notifications (or any combination of the three)
     *  - $snsTopic - the Amazon Resource Name (ARN) of the Amazon SNS topic
     *
     * @param array<int, string> $notificationTypes e.g., ['Bounce', 'Complaint', 'Delivery']
     *
     * @return array<int, Result<array>>
     */
    public function setIdentityNotificationTopic(string $identity, array $notificationTypes, string $snsTopic): array
    {
        $result = [];

        foreach ($notificationTypes as $notificationType) {
            $result[] = $this->getClient()->setIdentityNotificationTopic([
                'Identity'         => $identity,
                'NotificationType' => $notificationType,
                'SnsTopic'         => $snsTopic,
            ]);
        }

        return $result;
    }

    protected function getClient(): SesClient
    {
        return $this->transport->getSesClient();
    }
}
