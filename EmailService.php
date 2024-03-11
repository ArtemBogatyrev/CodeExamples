<?php

declare(strict_types=1);

namespace MauticPlugin\AivieAwsBundle\Service;

use Aws\Result;
use Exception;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\AivieAwsBundle\AwsEvents;
use MauticPlugin\AivieAwsBundle\Event\IdentityVerificationEvent;
use MauticPlugin\AivieAwsBundle\Service\SesV2AwsService;
use MauticPlugin\AivieAwsBundle\Template\EmailVerificationTemplate;
use MauticPlugin\AivieGcBundle\CoreBundle\Helper\ConfigHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailService
{
    public const SUCCESS_STATUS            = 'SUCCESS';
    public const PENDING_STATUS            = 'PENDING';
    public const NOT_STARTED_STATUS        = 'NOT_STARTED';
    public const FAILED_STATUS             = 'FAILED';
    public const TEMPORARY_FAILURE_STATUS  = 'TEMPORARY_FAILURE';
    public const IDENTITY_CREATED          = 'CREATED';
    public const NOT_FOUND_IDENTITY_STATUS = 'NOT_FOUND_IDENTITY';
    public const ERROR_STATUS              = 'ERROR';

    private TranslatorInterface $translator;
    private LoggerInterface $logger;
    private SesV2AwsService $sesV2AwsService;
    private ConfigHelper $configHelper;
    private CoreParametersHelper $coreParametersHelper;
    private EventDispatcherInterface $dispatcher;
    private EmailVerificationTemplate $emailVerificationTemplate;

    public function __construct(
        TranslatorInterface $translator,
        LoggerInterface $logger,
        SesV2AwsService $sesV2AwsService,
        ConfigHelper $configHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        EmailVerificationTemplate $emailVerificationTemplate
    ) {
        $this->translator                  = $translator;
        $this->logger                      = $logger;
        $this->sesV2AwsService             = $sesV2AwsService;
        $this->configHelper                = $configHelper;
        $this->coreParametersHelper        = $coreParametersHelper;
        $this->dispatcher                  = $dispatcher;
        $this->emailVerificationTemplate   = $emailVerificationTemplate;
    }

    /**
     * Check if the domain or email is valid.
     */
    public static function isValidEmail(string $domain): bool
    {
        $isValidEmail  = filter_var($domain, FILTER_VALIDATE_EMAIL);
        $isValidDomain = filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

        return $isValidEmail || $isValidDomain;
    }

    public static function getUserNameFromAddress(string $email): ?string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $array  = explode('@', $email);

        return array_shift($array);
    }

    /**
     * If the parameter is an email, get and return the domain.
     *
     * @throws Exception
     */
    public function getDomain(string $domain): string
    {
        if (!self::isValidEmail($domain)) {
            $message = $this->translator->trans('plugin.aivie_aws.no_valid_domain_name');
            throw new Exception($message);
        }

        if (filter_var($domain, FILTER_VALIDATE_EMAIL)) {
            $array  = explode('@', $domain);
            $domain = array_pop($array);
        }

        return $domain;
    }

    public function isDomainIdentityVerified(string $email): bool
    {
        $awsMailerFromDomainVerified  = $this->coreParametersHelper->get('aws_mailer_from_domain_verified');

        if (!empty($awsMailerFromDomainVerified)) {
            $awsMailerFromDomainVerified = json_decode($awsMailerFromDomainVerified, true);
            $domain                      = $this->getDomain(array_key_first($awsMailerFromDomainVerified));
            $mailerFromDomain            = $this->getDomain($email);

            return $domain === $mailerFromDomain && $awsMailerFromDomainVerified[$domain];
        }

        return false;
    }

    public function isEmailIdentityVerified(string $email): bool
    {
        $awsMailerFromAddressVerified = $this->coreParametersHelper->get('aws_mailer_from_email_address_verified');

        if (!empty($awsMailerFromAddressVerified)) {
            $awsMailerFromAddressVerified = json_decode($awsMailerFromAddressVerified, true);
            $address                      = array_key_first($awsMailerFromAddressVerified);

            return $address === $email && $awsMailerFromAddressVerified[$address];
        }

        return false;
    }

    /**
     * @return array<string, string|array<string, string|mixed>>
     */
    public function verify(string $identity, string $identityType = SesV2AwsService::EMAIL_ADDRESS_IDENTITY_TYPE): array
    {
        // check the AWS service
        $payload = $this->checkIdentity($identity, $identityType);

        if (SesV2AwsService::EMAIL_ADDRESS_IDENTITY_TYPE === $identityType
            && (EmailHelper::FAILED_STATUS === $payload['code'] || EmailHelper::PENDING_STATUS === $payload['code'])
        ) {
            $this->sendCustomVerificationEmail($identity);
        }

        // If there is no email in AWS, it will be created
        if (EmailHelper::NOT_FOUND_IDENTITY_STATUS === $payload['code']) {
            $payload = $this->createIdentity($identity);
        }

        $payload['message'] = $this->getIdentityStatusMessage($payload['code'], $identity);

        return $payload;
    }

    /**
     * @return array<string, string|array<string, string|mixed>>
     */
    public function checkIdentity(string $identity, string $identityType = SesV2AwsService::EMAIL_ADDRESS_IDENTITY_TYPE): array
    {
        try {
            $identity     = SesV2AwsService::DOMAIN_IDENTITY_TYPE === $identityType
                ? $this->getDomain($identity)
                : $identity;

            $identityData = $this->sesV2AwsService->getIdentity($identity);

            // null = identity not found
            if (null === $identityData) {
                $parameters = ['aws_mailer_from_'.strtolower($identityType).'_verified' => [$identity => false]];
                $this->configHelper->addParametersToLocalConfiguration($parameters);

                return ['code' => self::NOT_FOUND_IDENTITY_STATUS];
            }

            $isVerifiedForSending = $this->sesV2AwsService->isVerifiedForSendingStatus($identityData);
            $data['code']         = $isVerifiedForSending
                ? self::SUCCESS_STATUS
                : $this->sesV2AwsService->getDkimStatus($identityData);
            $identityData['DkimAttributes'] && $data['data'] = $identityData['DkimAttributes'];

            $parameters = ['aws_mailer_from_'.strtolower($identityType).'_verified' => [$identity => self::SUCCESS_STATUS == $data['code']]];
            $this->configHelper->addParametersToLocalConfiguration($parameters);
        } catch (\Exception $e) {
            $data['code'] = self::ERROR_STATUS;
            $this->logger->error('AivieAwsBundle:AjaxController: '.$e->getMessage());
        }

        return $data;
    }

    /**
     * @return array<string, string|array<string, string|mixed>>
     */
    public function createIdentity(string $identity, string $identityType = SesV2AwsService::EMAIL_ADDRESS_IDENTITY_TYPE): array
    {
        try {
            if (SesV2AwsService::EMAIL_ADDRESS_IDENTITY_TYPE === $identityType) {
                $identityData = $this->sendCustomVerificationEmail($identity);
            } else {
                $identity     = $this->getDomain($identity);
                $identityData = $this->sesV2AwsService->createIdentity($identity);
            }

            // Dispatch the event ON_VERIFY_IDENTITY for sending custom verification email templates
            $event  = new IdentityVerificationEvent($identity, $identityType);
            /** @phpstan-ignore-next-line Parameter #2 $eventName of method EventDispatcherInterface::dispatch() expects string|null, IdentityVerificationEvent given.*/
            $result = $this->dispatcher->dispatch(AwsEvents::ON_VERIFY_IDENTITY, $event);

            $data['code'] = $this->sesV2AwsService->getDkimStatus($identityData) ?? self::IDENTITY_CREATED;
            if (isset($identityData['DkimAttributes'])) {
                $data['data'] = $identityData['DkimAttributes'];
            }

            $parameters = ['aws_mailer_from_'.strtolower($identityType).'_verified' => [$identity => false]];
            $this->configHelper->addParametersToLocalConfiguration($parameters);

            $this->logger->info('AivieAwsBundle: EmailHelper: Identity created', ['%identity%' => $identity]);
        } catch (Exception $e) {
            $data['code'] = self::ERROR_STATUS;
            $this->logger->error('AivieAwsBundle: EmailHelper: '.$e->getMessage());
        }

        return $data;
    }

    public function getIdentityStatusMessage(string $status, string $identity = ''): string
    {
        switch ($status) {
            case self::SUCCESS_STATUS:
                $message = $this->translator->trans('plugin.aivie_aws.verified_identity');
                break;
            case self::PENDING_STATUS:
            case self::IDENTITY_CREATED:
                $message = $this->translator->trans('plugin.aivie_aws.verification_is_pending');
                break;
            case self::NOT_STARTED_STATUS:
                $message = $this->translator->trans('plugin.aivie_aws.verification_is_not_started');
                break;
            case self::FAILED_STATUS:
            case self::TEMPORARY_FAILURE_STATUS:
                $message = $this->translator->trans('plugin.aivie_aws.unverified_email', ['%email%' => $identity]);
                break;
            case self::NOT_FOUND_IDENTITY_STATUS:
                $message = $this->translator->trans('plugin.aivie_aws.identity_not_found', ['%email%' => $identity]);
                break;
            default:
                $message = $this->translator->trans('plugin.aivie_aws.error_identity_verification');
        }

        return $message;
    }

    /**
     * Check if the email verification template exists with AWS, otherwise create it.
     *
     * @return ?Result<array>
     */
    private function sendCustomVerificationEmail(string $identity): ?Result
    {
        $templateName = $this->emailVerificationTemplate->getTemplateName();
        if (false === $this->sesV2AwsService->isVerificationEmailTemplateExists($templateName)) {
            $template = $this->emailVerificationTemplate->getTemplate();
            $this->logger->info('EmailHelper: Template does not exist. Creating it now', ['template'=>$template['templateName'], 'TEMPLATE_NAME'=>$templateName]);
            $this->sesV2AwsService->createCustomVerificationEmailTemplate(
                $template['templateName'],
                $template['fromEmailAddress'],
                $template['templateSubject'],
                $template['templateContent'],
                $template['successRedirectionURL'],
                $template['failureRedirectionURL']
            );
        }

        return $this->sesV2AwsService->sendCustomVerificationEmail($identity, $templateName);
    }
}
