<?php

declare(strict_types=1);

namespace MauticPlugin\AivieAwsBundle\Tests\Service;

use Aws\Result;
use Aws\SesV2\SesV2Client;
use Generator;
use MauticPlugin\AivieAwsBundle\Service\SesV2AwsService;
use MauticPlugin\AivieAwsBundle\Swiftmailer\Transport\AmazonApiTransport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SesV2AwsServiceTest extends TestCase
{
    /** @var SesV2Client|MockObject */
    public $sesV2Client;

    /** @var AmazonApiTransport|MockObject */
    public $transport;

    public SesV2AwsService $SesV2AwsService;

    public function setUp(): void
    {
        parent::setUp();

        $this->transport         = $this->createMock(AmazonApiTransport::class);
        $this->sesV2Client       = $this->getMockBuilder(SesV2Client::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->addMethods([
                'listCustomVerificationEmailTemplates',
                'createCustomVerificationEmailTemplate',
                'sendCustomVerificationEmail',
                ])
            ->getMock();
        $logger = $this->createMock(LoggerInterface::class);

        $this->SesV2AwsService = new SesV2AwsService(
            $this->transport,
            $logger
        );
    }

    /**
     * @dataProvider dataProvider
     *
     * @param array<string, string>       $input
     * @param array<string, array<mixed>> $expected
     */
    public function testIsVerificationEmailTemplateExists(array $input, array $expected): void
    {
        $this->transport->expects($this->once())
            ->method('getSesV2Client')
            ->willReturn($this->sesV2Client);
        $this->sesV2Client->expects($this->once())
            ->method('listCustomVerificationEmailTemplates')
            ->willReturn(new Result($expected));

        $result = $this->SesV2AwsService->isVerificationEmailTemplateExists($input['templateName']);

        $this->assertTrue($result);
    }

    /**
     * @dataProvider dataProvider
     *
     * @param array<string, string>       $input
     * @param array<string, array<mixed>> $expected
     */
    public function testSendCustomVerificationEmail(array $input, array $expected): void
    {
        $this->transport->expects($this->exactly(2))
            ->method('getSesV2Client')
            ->willReturn($this->sesV2Client);

        $this->sesV2Client->expects($this->once())
            ->method('listCustomVerificationEmailTemplates')
            ->willReturn(new Result($expected));

        $this->sesV2Client->expects($this->once())
            ->method('sendCustomVerificationEmail');

        $this->SesV2AwsService->sendCustomVerificationEmail($input['emailAddress'], $input['templateName']);
    }

    /** 
     * @return Generator<array<mixed>>
     */
    public function dataProvider(): Generator
    {
        yield [
            [
                'emailAddress' => 'test@test.com',
                'templateName' => 'DefaultTemplate',
            ],
            [
                'CustomVerificationEmailTemplates' => [
                    [
                        'FailureRedirectionURL' => 'test',
                        'FromEmailAddress'      => 'test',
                        'SuccessRedirectionURL' => 'test',
                        'TemplateName'          => 'DefaultTemplate',
                        'TemplateSubject'       => 'test',
                    ],
                ],
                'NextToken' => '2',
            ],
        ];
    }
}
