<?php

use AmjadIqbal\BlockCraft\FormWidgets\BlockCraft;
use Backend\Classes\FormField;
use Illuminate\Database\Eloquent\Model;

/**
 * BlockCraftTest exercises the BlockCraft FormWidget directly against
 * October's real Backend\Classes\FormField / FormWidgetBase machinery (not
 * mocks) - the same approach verified in VueForge's own VueWidgetTest.
 *
 * Run from an October CMS application root that has this plugin installed
 * under plugins/amjadiqbal/blockcraft, e.g.:
 *
 *     vendor/bin/phpunit -c phpunit.xml --testsuite "BlockCraft"
 */
class BlockCraftFixtureModel extends Model
{
    public $table = 'blockcraft_fixture';

    protected $guarded = [];
}

class BlockCraftTest extends PluginTestCase
{
    protected function makeWidget(array $fieldConfig = [], $model = null): BlockCraft
    {
        $model = $model ?: new BlockCraftFixtureModel;

        $field = new FormField(array_merge([
            'fieldName' => 'body',
        ], array_intersect_key($fieldConfig, array_flip(['valueFrom', 'value']))));

        $widgetConfig = array_diff_key($fieldConfig, array_flip(['valueFrom', 'value']));
        $widgetConfig['model'] = $model;

        return new BlockCraft(null, $field, $widgetConfig);
    }

    public function testDefaultOutputFormatIsHtml()
    {
        $widget = $this->makeWidget();

        $this->assertSame('html', $widget->outputFormat);
    }

    public function testInvalidOutputFormatFallsBackToHtml()
    {
        $widget = $this->makeWidget(['outputFormat' => 'yaml']);

        $this->assertSame('html', $widget->outputFormat);
    }

    public function testJsonOutputFormatIsRespected()
    {
        $widget = $this->makeWidget(['outputFormat' => 'json']);

        $this->assertSame('json', $widget->outputFormat);
    }

    public function testDisabledFieldForcesReadOnly()
    {
        $field = new FormField(['fieldName' => 'body']);
        $field->disabled(true);

        $widget = new BlockCraft(null, $field, ['model' => new BlockCraftFixtureModel]);

        $this->assertTrue($widget->readOnly);
    }

    public function testPrepareVarsSerializesHtmlValueAsAttributeSafeJson()
    {
        $model = new BlockCraftFixtureModel;
        $model->body = '<p>Hello "world"</p>';

        $widget = $this->makeWidget(['valueFrom' => 'body'], $model);
        $widget->prepareVars();

        $decoded = json_decode(htmlspecialchars_decode($widget->vars['value'], ENT_QUOTES), true);

        $this->assertSame('<p>Hello "world"</p>', $decoded);
        $this->assertStringNotContainsString('"', $widget->vars['value']);
    }

    public function testPrepareVarsSanitizesAJsonValueBeforeDisplay()
    {
        $model = new BlockCraftFixtureModel;
        $model->body = ['type' => 'doc', 'content' => [['type' => 'evilNode']]];

        $widget = $this->makeWidget(['outputFormat' => 'json', 'valueFrom' => 'body'], $model);
        $widget->prepareVars();

        $decoded = json_decode(htmlspecialchars_decode($widget->vars['value'], ENT_QUOTES), true);

        $this->assertSame(['type' => 'doc', 'content' => []], $decoded);
    }

    public function testPrepareVarsConfigReflectsMediaManagerAccessForAnUnauthenticatedRequest()
    {
        // No backend user is logged in within a plain PluginTestCase, so
        // BackendAuth::userHasAccess('media.library') genuinely returns
        // false here - this exercises the real Facade, not a mock.
        // Mockery isn't a dependency of this plugin (or of a throwaway
        // october/october app's own dev requirements), so the "access
        // granted" branch isn't separately mocked here; prepareVars() is a
        // direct one-line pass-through of that call's return value (see
        // BlockCraft.php), so this one real assertion covers the wiring.
        $widget = $this->makeWidget();
        $widget->prepareVars();

        $config = json_decode(htmlspecialchars_decode($widget->vars['config'], ENT_QUOTES), true);

        $this->assertFalse($config['useMediaManager']);
    }

    public function testGetSaveValueSanitizesHtmlAgainstXss()
    {
        $widget = $this->makeWidget();

        $result = $widget->getSaveValue('<p>safe</p><script>alert(1)</script>');

        $this->assertStringContainsString('<p>safe</p>', $result);
        $this->assertStringNotContainsString('<script', $result);
    }

    public function testGetSaveValueValidatesJsonPayload()
    {
        $widget = $this->makeWidget(['outputFormat' => 'json']);

        $result = $widget->getSaveValue(json_encode([
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ok']]]],
        ]));

        $this->assertSame('doc', $result['type']);
        $this->assertSame('paragraph', $result['content'][0]['type']);
    }

    public function testGetSaveValueOnMalformedJsonReturnsEmptyDocument()
    {
        $widget = $this->makeWidget(['outputFormat' => 'json']);

        $result = $widget->getSaveValue('{not valid json');

        $this->assertSame(['type' => 'doc', 'content' => []], $result);
    }

    public function testGetSaveValueRespectsDisabledFieldByReturningLoadedValue()
    {
        $model = new BlockCraftFixtureModel;
        $model->body = '<p>existing</p>';

        $field = new FormField(['fieldName' => 'body', 'valueFrom' => 'body']);
        $field->disabled(true);

        $widget = new BlockCraft(null, $field, ['model' => $model]);

        $result = $widget->getSaveValue('<p>attempted override</p>');

        $this->assertSame('<p>existing</p>', $result);
    }

    public function testGetSaveValueOnEmptyHtmlStringReturnsEmptyString()
    {
        $widget = $this->makeWidget();

        $this->assertSame('', $widget->getSaveValue(''));
    }
}
