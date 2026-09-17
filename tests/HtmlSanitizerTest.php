<?php

use AmjadIqbal\BlockCraft\Classes\HtmlSanitizer;

/**
 * HtmlSanitizerTest exercises HtmlSanitizer against real XSS vectors and
 * BlockCraft's actual allow-listed markup - no mocking, plain string in,
 * plain string out.
 *
 * Run from an October CMS application root that has this plugin installed
 * under plugins/amjadiqbal/blockcraft, e.g.:
 *
 *     vendor/bin/phpunit -c phpunit.xml --testsuite "BlockCraft"
 */
class HtmlSanitizerTest extends PluginTestCase
{
    protected HtmlSanitizer $sanitizer;

    public function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testAllowedTagsAndAttributesArePreserved()
    {
        $html = '<h1>Title</h1><p>Hello <strong>world</strong> and <em>friends</em></p>'
            . '<ul><li>One</li><li>Two</li></ul>'
            . '<a href="https://example.com" target="_blank">link</a>'
            . '<img src="/storage/app/media/photo.jpg" alt="A photo">';

        $result = $this->sanitizer->clean($html);

        $this->assertStringContainsString('<h1>Title</h1>', $result);
        $this->assertStringContainsString('<strong>world</strong>', $result);
        $this->assertStringContainsString('<li>One</li>', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('src="/storage/app/media/photo.jpg"', $result);
        $this->assertStringContainsString('alt="A photo"', $result);
    }

    public function testScriptTagsAreRemovedEntirelyIncludingContent()
    {
        $result = $this->sanitizer->clean('<p>Before</p><script>alert(document.cookie)</script><p>After</p>');

        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert(document.cookie)', $result);
        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
    }

    public function testOnErrorAttributeIsStrippedFromAnImage()
    {
        $result = $this->sanitizer->clean('<img src="x.jpg" onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
        $this->assertStringContainsString('src="x.jpg"', $result);
    }

    public function testJavascriptUriInHrefIsStripped()
    {
        $result = $this->sanitizer->clean('<a href="javascript:alert(1)">click me</a>');

        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('click me', $result);
    }

    public function testDataUriInImageSrcIsStripped()
    {
        // allowBase64 defaults to false on the frontend Image extension - a
        // data: URI should never legitimately reach the server, but a
        // crafted POST could still try.
        $result = $this->sanitizer->clean('<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">');

        $this->assertStringNotContainsString('data:', $result);
    }

    public function testStyleAttributeIsAlwaysStripped()
    {
        $result = $this->sanitizer->clean('<p style="background:url(javascript:alert(1))">text</p>');

        $this->assertStringNotContainsString('style=', $result);
        $this->assertStringContainsString('text', $result);
    }

    public function testIframeObjectEmbedAndFormAreRemovedEntirely()
    {
        $html = '<iframe src="https://evil.example"></iframe>'
            . '<object data="evil.swf"></object>'
            . '<embed src="evil.swf">'
            . '<form action="https://evil.example"><input></form>'
            . '<p>safe</p>';

        $result = $this->sanitizer->clean($html);

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringNotContainsString('<object', $result);
        $this->assertStringNotContainsString('<embed', $result);
        $this->assertStringNotContainsString('<form', $result);
        $this->assertStringContainsString('safe', $result);
    }

    public function testUnknownTagsAreUnwrappedButSafeChildContentIsKept()
    {
        // A tag not in the allow-list (e.g. <div>, <span>) is removed while
        // its own safe children survive - matches what a paste from an
        // arbitrary web page might look like.
        $result = $this->sanitizer->clean('<div class="wrapper"><p>kept</p></div>');

        $this->assertStringNotContainsString('<div', $result);
        $this->assertStringContainsString('<p>kept</p>', $result);
    }

    public function testTargetBlankLinkGetsNoopenerNoreferrer()
    {
        $result = $this->sanitizer->clean('<a href="https://example.com" target="_blank">go</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function testEmptyAndNullInputReturnEmptyString()
    {
        $this->assertSame('', $this->sanitizer->clean(''));
        $this->assertSame('', $this->sanitizer->clean(null));
        $this->assertSame('', $this->sanitizer->clean('   '));
    }

    public function testMalformedHtmlDegradesGracefullyInsteadOfThrowing()
    {
        $result = $this->sanitizer->clean('<p>unclosed <strong>tags <em>everywhere');

        $this->assertStringContainsString('unclosed', $result);
    }

    public function testTableMarkupIsPreserved()
    {
        $html = '<table><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Row</td></tr></tbody></table>';

        $result = $this->sanitizer->clean($html);

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<th>Name</th>', $result);
        $this->assertStringContainsString('<td>Row</td>', $result);
    }
}
