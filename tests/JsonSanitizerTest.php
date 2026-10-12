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

    public function testImageWidthAndAlignAreKeptOnlyWithValidValues()
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/a.jpg', 'alt' => 'A', 'width' => 320, 'height' => '240', 'align' => 'center']],
            ['type' => 'image', 'attrs' => ['src' => '/b.jpg', 'width' => '100px', 'height' => -5, 'align' => 'javascript:alert(1)']],
        ]];

        $result = $this->sanitizer->clean($doc);

        $this->assertSame(['src' => '/a.jpg', 'alt' => 'A', 'width' => 320, 'height' => '240', 'align' => 'center'], $result['content'][0]['attrs']);
        $this->assertSame(['src' => '/b.jpg'], $result['content'][1]['attrs']);
    }

    public function testTableCellColwidthListIsKeptOnlyAsPositiveIntegers()
    {
        $cell = fn ($colwidth) => ['type' => 'tableCell', 'attrs' => ['colspan' => 2, 'rowspan' => 1, 'colwidth' => $colwidth],
            'content' => [['type' => 'paragraph']]];

        $doc = ['type' => 'doc', 'content' => [[
            'type' => 'table',
            'content' => [[
                'type' => 'tableRow',
                'content' => [$cell([200, 150]), $cell(['200', 'x']), $cell(['a' => 1]), $cell(null)],
            ]],
        ]]];

        $cells = $this->sanitizer->clean($doc)['content'][0]['content'][0]['content'];

        $this->assertSame([200, 150], $cells[0]['attrs']['colwidth']);
        $this->assertArrayNotHasKey('colwidth', $cells[1]['attrs']);
        $this->assertArrayNotHasKey('colwidth', $cells[2]['attrs']);
        $this->assertArrayHasKey('colwidth', $cells[3]['attrs']);
        $this->assertNull($cells[3]['attrs']['colwidth']);
    }
}
