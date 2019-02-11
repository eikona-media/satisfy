<?php

namespace Tests\Playbloom\Satisfy\Webhook;

use Playbloom\Satisfy\Model\Repository;
use Playbloom\Satisfy\Runner\SatisBuildRunner;
use Playbloom\Satisfy\Service\Manager;
use Playbloom\Satisfy\Webhook\BitbucketWebhook;
use Prophecy\Argument;
use RDV\SymfonyContainerMocks\DependencyInjection\TestContainer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Tests\Playbloom\Satisfy\Traits\VfsTrait;

class BitbucketWebhookTest extends KernelTestCase
{
    use VfsTrait;

    protected function setUp()
    {
        $this->vfsSetup();
        self::bootKernel();
    }

    /**
     * @dataProvider invalidRequestProvider
     */
    public function testInvalidRequestMustThrowException($request)
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @var BitbucketWebhook $handler */
        $handler = self::$kernel->getContainer()->get('satisfy.webhook.bitbucket');
        $handler->handle($request);
    }

    public function invalidRequestProvider()
    {
        // invalid IP
        yield [$this->createRequest('', '1.1.1.1')];

        // valid IP, missing content
        yield [Request::create('')];

        // valid IP, missing required param
        $content = ['repository' => ['full_name' => '']];
        yield [$this->createRequest($content)];

        // valid content, unknown repository url
        $content = ['repository' => ['full_name' => 'test/test']];
        yield [$this->createRequest($content)];
    }

    public function testValidRequestMustTriggerBuild()
    {
        /** @var TestContainer $container */
        $container = self::$kernel->getContainer();
        $rootPath = $container->getParameter('kernel.project_dir');

        $processFactory = $container->prophesize('satisfy.process.factory');
        /** @var SatisBuildRunner $builder */
        $builder = $container->get('satisfy.runner.satis_build');
        $builder->setProcessFactory($processFactory->reveal());

        /** @var Manager $manager */
        $manager = $container->get('satisfy.manager');
        $manager->add(new Repository('git@bitbucket.org:test/test.git'));

        $process = $this->prophesize(Process::class);
        $process
            ->disableOutput()
            ->shouldBeCalled();
        $process
            ->run()
            ->shouldBeCalled()
            ->willReturn(0);

        $command = '/bin/console satisfy:rebuild --skip-errors --no-ansi --verbose';
        $command .= ' --repository-url="git@bitbucket.org:test/test.git"';
        $processFactory
            ->create(Argument::containingString($command), Argument::type('integer'))
            ->willReturn($process->reveal());
        $processFactory
            ->getRootPath()
            ->willReturn($rootPath);

        $request = $this->createRequest(['repository' => ['full_name' => 'test/test']]);
        /** @var BitbucketWebhook $webhook */
        $webhook = $container->get('satisfy.webhook.bitbucket');
        $status = $webhook->handle($request);

        $this->assertEquals(0, $status);
    }

    protected function createRequest($content, string $ipAddress = '127.0.0.1'): Request
    {
        return Request::create('', 'GET', [], [], [], ['REMOTE_ADDR' => $ipAddress], json_encode($content));
    }
}
