<?php

use AmjadIqbal\BlockCraft\Classes\JsonSanitizer;

/**
 * JsonSanitizerTest exercises JsonSanitizer against valid TipTap documents,
 * malformed/malicious payloads, and structures that don't match TipTap's
 * schema at all.
 */
class JsonSanitizerTest extends PluginTestCase
{
    protected JsonSanitizer $sanitizer;

    public function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new JsonSanitizer();
    }

    public function testValidDocumentPassesThroughIntact()
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 2],
                    'content' => [['type' => 'text', 'text' => 'Title']],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello '],
                        ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
                    ],
                ],
            ],
        ];

        $result = $this->sanitizer->clean($doc);

        $this->assertSame($doc, $result);
    }

    public function testNonArrayOrWrongTopLevelTypeReturnsEmptyDocument()
    {
        $this->assertSame(['type' => 'doc', 'content' => []], $this->sanitizer->clean(null));
        $this->assertSame(['type' => 'doc', 'content' => []], $this->sanitizer->clean('not an array'));
        $this->assertSame(['type' => 'doc', 'content' => []], $this->sanitizer->clean(['type' => 'paragraph']));
        $this->assertSame(['type' => 'doc', 'content' => []], $this->sanitizer->clean([]));
    }

    public function testUnknownNodeTypesAreDroppedEntirely()
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'kept']]],
                ['type' => 'maliciousScriptNode', 'content' => [['type' => 'text', 'text' => 'dropped']]],
            ],
        ];

        $result = $this->sanitizer->clean($doc);

        $this->assertCount(1, $result['content']);
        $this->assertSame('paragraph', $result['content'][0]['type']);
    }

    public function testUnknownMarkTypesAreDroppedButKnownMarksSurvive()
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'styled',
                    'marks' => [['type' => 'bold'], ['type' => 'onclick-injection']],
                ]],
            ]],
        ];

        $result = $this->sanitizer->clean($doc);
        $marks = $result['content'][0]['content'][0]['marks'];

        $this->assertCount(1, $marks);
        $this->assertSame('bold', $marks[0]['type']);
    }

    public function testLinkMarkWithoutHrefIsDropped()
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'link',
                    'marks' => [['type' => 'link', 'attrs' => ['target' => '_blank']]],
                ]],
            ]],
        ];

        $result = $this->sanitizer->clean($doc);

        $this->assertArrayNotHasKey('marks', $result['content'][0]['content'][0]);
    }

    public function testLinkMarkWithJavascriptHrefIsDropped()
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'link',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
                ]],
            ]],
        ];

        $result = $this->sanitizer->clean($doc);

        $this->assertArrayNotHasKey('marks', $result['content'][0]['content'][0]);
    }

    public function testImageNodeKeepsOnlyAllowedAttrs()
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => [
                    'src' => '/storage/photo.jpg',
                    'alt' => 'A photo',
                    'align' => 'left',
                    'onerror' => 'alert(1)',
                ],
            ]],
        ];

        $result = $this->sanitizer->clean($doc);
        $attrs = $result['content'][0]['attrs'];

        $this->assertSame('/storage/photo.jpg', $attrs['src']);
        $this->assertSame('left', $attrs['align']);
        $this->assertArrayNotHasKey('onerror', $attrs);
    }

    public function testUnexpectedAttributeValueTypesAreDropped()
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => ['nested' => 'array is not scalar']],
            ]],
        ];

        $result = $this->sanitizer->clean($doc);

        $this->assertArrayNotHasKey('attrs', $result['content'][0]);
    }

    public function testDeeplyNestedContentIsSanitizedRecursively()
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'bulletList',
                'content' => [[
                    'type' => 'listItem',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'evilNode', 'text' => 'x']],
                    ]],
                ]],
            ]],
        ];

        $result = $this->sanitizer->clean($doc);

        $this->assertSame([], $result['content'][0]['content'][0]['content'][0]['content']);
    }

    public function testNonArrayContentDoesNotCrash()
    {
        $doc = ['type' => 'doc', 'content' => 'not an array'];

        $result = $this->sanitizer->clean($doc);

        $this->assertSame(['type' => 'doc', 'content' => []], $result);
    }
}
