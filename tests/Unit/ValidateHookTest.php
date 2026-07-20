<?php

namespace MorganEstes\Terminus\Tests\Unit;

use Consolidation\AnnotatedCommand\CommandData;
use GuzzleHttp\Exception\GuzzleException;
use MorganEstes\Terminus\Hooks\ValidateHook;
use MorganEstes\Terminus\Traits\PantheonHelperTrait;
use Pantheon\Terminus\Collections\Sites;
use Pantheon\Terminus\DataStore\DataStoreInterface;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;

class ValidateHookTest extends TestCase
{
    use PantheonHelperTrait;

    private const UUID = '8b264087-4a48-45ea-a3f1-62dffb013443';

    private $validateHook;
    private $commandData;
    private $input;
    protected $sites;
    private $site;
    private $session;
    private $dataStore;

    protected function setUp(): void
    {
        // Create mocks for all dependencies.
        $this->commandData = $this->createMock(CommandData::class);
        $this->input = $this->createMock(InputInterface::class);
        $this->sites = $this->createMock(Sites::class);
        $this->site = $this->createMock(Site::class);
        $this->session = $this->createMock(Session::class);
        $this->dataStore = $this->createMock(DataStoreInterface::class);
        $this->createPartialMock(self::class,['sites', 'session']);
        $this->createPartialMock($this->session, ['getDataStore']);

        // Configure the mocks.
        $this->commandData->method('input')->willReturn($this->input);
        $this->session->method('getDataStore')->willReturn($this->dataStore);
        $this->sites->method('get')->withAnyParameters()->willReturn($this->site);
        $this->site->method('getName')->willReturn('my-canonical-site-name');
        $this->site->method('serialize')->willReturn(['id' => self::UUID, 'name' => 'my-canonical-site-name']);

        // Instantiate the class under test.
        $this->validateHook = new ValidateHook();

        // Inject the dependencies.
        $this->validateHook->setSites($this->sites);
        $this->validateHook->setSession($this->session);
    }

    public function session(): Session&MockObject
    {
        return $this->session;
    }
    public function sites(): Sites&MockObject
    {
        return $this->sites;
    }

    public function getDataStore(): DataStoreInterface&MockObject
    {
        return $this->dataStore;
    }

    /**
     * @throws TerminusException
     * @throws ContainerExceptionInterface
     * @throws GuzzleException
     */
    #[DataProvider('siteIdentifierProvider')]
    public function testNormalizeSiteName($rawInput, $expectedIdentifier): void
    {
        // Configure the input mock to return the raw input for this test case.
        $this->input->expects($this->once())
            ->method('getArgument')
            ->with(self::SITE_NAME_INPUT)
            ->willReturn($rawInput);

        // Expect the sites collection to be asked to 'get' the final, clean identifier.
        $this->sites()->expects($this->once())
            ->method('get')
            ->with($expectedIdentifier)
            ->willReturn($this->site);

        // Expect the data store to have the serialized site data set.
        $this->getDataStore()->expects($this->once())
            ->method('get')
            ->with(self::DATA_STORE_NAME)
            ->willReturn((object)['id' => self::UUID, 'name' => 'my-canonical-site-name']);

        // Expect the input argument to be updated with the canonical name.
        $this->input->expects($this->once())
            ->method('setArgument')
            ->with(self::SITE_NAME_INPUT, 'my-canonical-site-name');

        // Run the method under test.
        $this->validateHook->normalizeSiteName($this->commandData);
    }

    public static function siteIdentifierProvider(): array
    {
        $site_uuid = self::UUID;
        return [
            'Plain site name' => [
                'my-cool-site',
                'my-cool-site'
            ],
            'Site name with env' => [
                'my-cool-site.dev',
                'my-cool-site'
            ],
            'Plain UUID' => [
                $site_uuid,
                $site_uuid
            ],
            'Platform URL' => [
                'https://dev-my-cool-site.pantheonsite.io',
                'my-cool-site'
            ],
            'Old Dashboard URL' => [
                "https://dashboard.pantheon.io/sites/{$site_uuid}",
                $site_uuid
            ],
            'New Dashboard URL with /frozen' => [
                "https://dashboard.pantheon.io/workspace/f121597b-4c04-479b-9920-f1571df2ec41/cms-site/{$site_uuid}/frozen",
                $site_uuid
            ],
            'New Dashboard URL with /environment (the failing case)' => [
                "https://dashboard.pantheon.io/workspace/f121597b-4c04-479b-9920-f1571df2ec41/cms-site/{$site_uuid}/environment/dev/code",
                $site_uuid
            ],
        ];
    }
}
