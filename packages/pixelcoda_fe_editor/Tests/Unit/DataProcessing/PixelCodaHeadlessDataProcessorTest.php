<?php

declare(strict_types=1);

namespace PixelCoda\FeEditor\Tests\Unit\DataProcessing;

use PixelCoda\FeEditor\DataProcessing\PixelCodaHeadlessDataProcessor;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class PixelCodaHeadlessDataProcessorTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @test
     */
    public function processReturnsDataWithoutMetadataIfNoBackendUser(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturn(['headless' => ['enabled' => true]]);

        $subject = new PixelCodaHeadlessDataProcessor($uriBuilder, $extConfig);

        $cObj = new ContentObjectRenderer();
        $cObj->data = ['uid' => 123, 'CType' => 'text'];

        $processedData = ['data' => $cObj->data];

        $GLOBALS['BE_USER'] = null;

        $result = $subject->process($cObj, [], [], $processedData);

        $this->assertArrayNotHasKey('_pixelcoda', $result);
    }

    /**
     * @test
     */
    public function processAddsMetadataIfBackendUserHasPermissions(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn('https://example.com/edit');

        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturn(['headless' => ['enabled' => true, 'exposeSensitive' => true]]);

        $subject = new PixelCodaHeadlessDataProcessor($uriBuilder, $extConfig);

        $cObj = $this->createMock(ContentObjectRenderer::class);
        $cObj->data = [
            'uid' => 123,
            'pid' => 45,
            'CType' => 'textmedia',
            'sys_language_uid' => 0,
            't3ver_wsid' => 0
        ];

        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $beUser;

        $result = $subject->process($cObj, [], [], []);

        $this->assertArrayHasKey('_pixelcoda', $result);
        $this->assertEquals(123, $result['_pixelcoda']['uid']);
        $this->assertEquals('textmedia', $result['_pixelcoda']['ctype']);
        $this->assertEquals('https://example.com/edit', $result['_pixelcoda']['backendEditUrl']);
        $this->assertEquals(45, $result['_pixelcoda']['pid']);
    }

    /**
     * @test
     */
    public function processDoesNotAddSensitiveDataIfConfiguredOff(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn('https://example.com/edit');

        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturn(['headless' => ['enabled' => true, 'exposeSensitive' => false]]);

        $subject = new PixelCodaHeadlessDataProcessor($uriBuilder, $extConfig);

        $cObj = $this->createMock(ContentObjectRenderer::class);
        $cObj->data = [
            'uid' => 123,
            'pid' => 45,
            'CType' => 'text',
        ];

        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $beUser;

        $result = $subject->process($cObj, [], [], []);

        $this->assertArrayHasKey('_pixelcoda', $result);
        $this->assertArrayNotHasKey('pid', $result['_pixelcoda']);
        $this->assertArrayNotHasKey('language', $result['_pixelcoda']);
    }
}
