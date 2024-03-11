<?php

declare(strict_types=1);

namespace MauticPlugin\AivieAwsBundle\Service;

use Aws\Result;
use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;
use Exception;
use MauticPlugin\AivieAwsBundle\Swiftmailer\Transport\AmazonApiTransport;
use Psr\Log\LoggerInterface;

class SesV2AwsService extends AwsService
{
    const DOMAIN_IDENTITY_TYPE        = 'DOMAIN';
    const EMAIL_ADDRESS_IDENTITY_TYPE = 'EMAIL_ADDRESS';

    private LoggerInterface $logger;

    public function __construct(AmazonApiTransport $transport, LoggerInterface $logger)
    {
        parent::__construct($transport);

        $this->logger = $logger;
    }

    /**
     * Provides information about a specific identity, including the identity's verification status.
     *
     * @return ?Result<array>
     *
     * @throws Exception
     */
    public function getIdentity(string $emailIdentity): ?Result
    {
        try {
            $emailIdentityData = $this->getClient()->getEmailIdentity([
                'EmailIdentity' => $emailIdentity,
            ]);
        } catch (SesV2Exception $e) {
            $statusCode = $e->getStatusCode();
            if ('NotFoundException' === $e->getAwsErrorCode()) {
                return null;
            }

            // The NotFoundException is not logged as an error.
            $this->logger->error($e->getMessage());

            throw new Exception($e->getAwsErrorMessage(), $statusCode, $e);
        }

        return $emailIdentityData;
    }

    /**
     * Starts the process of verifying an email identity. An identity is an email address or domain.
     *
     * @param array<string, string>|null $dkimSigningAttributes
     * @param array<string, string>|null $tags
     *
     * @return Result<array>
     */
    public function createIdentity(string $emailIdentity, string $configurationSetName = null, array $dkimSigningAttributes = null, array $tags = null): ?Result
    {
        $parameters                           = ['EmailIdentity' => $emailIdentity];
        $configurationSetName && $parameters  = array_merge($parameters, ['ConfigurationSetName' => $configurationSetName]);
        $dkimSigningAttributes && $parameters = array_merge($parameters, ['DkimSigningAttributes' => $dkimSigningAttributes]);
        $tags && $parameters                  = array_merge($parameters, ['Tags' => $tags]);

        return $this->getClient()->createEmailIdentity($parameters);
    }

    /**
     * Used to enable or disable DKIM authentication for an email identity.
     */
    public function putEmailIdentityDkimAttributes(string $emailIdentity, bool $signingEnabled = true): void
    {
        $parameters = [
            'EmailIdentity'  => $emailIdentity,
            'SigningEnabled' => $signingEnabled,
        ];

        $this->getClient()->putEmailIdentityDkimAttributes($parameters);
    }

    /**
     * Used to enable or disable the custom Mail-From domain configuration for an email identity.
     */
    public function putEmailIdentityMailFromAttributes(string $emailIdentity, string $mailFromDomain = null, string $behaviorOnMxFailure = null): void
    {
        $parameters                         = ['EmailIdentity' => $emailIdentity];
        $mailFromDomain && $parameters      = array_merge($parameters, ['MailFromDomain' => $mailFromDomain]);
        $behaviorOnMxFailure && $parameters = array_merge($parameters, ['BehaviorOnMxFailure' => $behaviorOnMxFailure]);

        $this->getClient()->putEmailIdentityMailFromAttributes($parameters);
    }

    /**
     * Used to associate a configuration set with an email identity.
     */
    public function putEmailIdentityFeedbackAttributes(string $emailIdentity, bool $emailForwardingEnabled): void
    {
        $parameters = [
            'EmailForwardingEnabled' => $emailForwardingEnabled,
            'EmailIdentity'          => $emailIdentity,
        ];

        $this->getClient()->putEmailIdentityFeedbackAttributes($parameters);
    }

    /**
     * Creates a new custom verification email template.
     *
     * @return Result<array>
     */
    public function createCustomVerificationEmailTemplate(
        string $templateName,
        string $fromEmailAddress,
        string $templateSubject,
        string $templateContent,
        string $successRedirectionURL = '',
        string $failureRedirectionURL = ''
    ): ?Result {
        try {
            return $this->getClient()->createCustomVerificationEmailTemplate([
                'FailureRedirectionURL' => $failureRedirectionURL,
                'FromEmailAddress'      => $fromEmailAddress,
                'SuccessRedirectionURL' => $successRedirectionURL,
                'TemplateContent'       => $templateContent,
                'TemplateName'          => $templateName,
                'TemplateSubject'       => $templateSubject,
            ]);
        } catch (SesV2Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    public function isVerificationEmailTemplateExists(string $templateName): bool
    {
        $list = $this->getClient()->listCustomVerificationEmailTemplates();

        if (null !== $list) {
            $list = array_filter(
                $list['CustomVerificationEmailTemplates'],
                fn ($elem) => $elem['TemplateName'] === $templateName
            );
        }

        return !empty($list);
    }

    /**
     * SHOULD be used only for EMAIL_ADDRESS_IDENTITY_TYPE.
     *
     * Adds an email address to the list of identities for your Amazon SES account in the current Amazon Web Services Region and attempts to verify it.
     * As a result of executing this operation, a customized verification email is sent to the specified address.
     *
     * @return Result<array>
     */
    public function sendCustomVerificationEmail(
        string $emailAddress,
        string $templateName,
        ?string $configurationSetName = null
    ): ?Result {
        if (false === $this->isVerificationEmailTemplateExists($templateName)) {
            return null;
        }

        try {
            $data = [
                'TemplateName' => $templateName,
                'EmailAddress' => $emailAddress,
            ];

            if (isset($configurationSetName)) {
                $data = array_merge($data, ['ConfigurationSetName' => $configurationSetName]);
            }

            return $this->getClient()->sendCustomVerificationEmail($data);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    /**
     * Check the DKIM status of the client domain.
     *
     * @param Result<array> $emailIdentityData
     */
    public function isVerifiedForSendingStatus(Result $emailIdentityData): bool
    {
        return $emailIdentityData['VerifiedForSendingStatus'];
    }

    /**
     * Check the DKIM status of the client domain.
     *
     * @param Result<array> $emailIdentityData
     */
    public function getDkimStatus(Result $emailIdentityData): ?string
    {
        return $emailIdentityData['DkimAttributes']['Status'] ?? null;
    }

    protected function getClient(): SesV2Client
    {
        return $this->transport->getSesV2Client();
    }
}
