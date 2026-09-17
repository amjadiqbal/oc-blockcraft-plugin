<?php

namespace AmjadIqbal\BlockCraft\FormWidgets;

use Backend\Classes\FormWidgetBase;
use BackendAuth;
use AmjadIqbal\BlockCraft\Classes\HtmlSanitizer;
use AmjadIqbal\BlockCraft\Classes\JsonSanitizer;
use AmjadIqbal\BlockCraft\Classes\ViteResolver;

/**
 * BlockCraft is a FormWidget that hydrates a Vue 3 + TipTap block editor
 * inside an October CMS backend form, in place of the native Froala
 * `richeditor` widget - see ../README.md for the "why" and
 * ../PROJECT_PROGRESS.md for what was verified against real October source.
 *
 * YAML usage:
 *
 *     body:
 *         label: Body
 *         type: blockcraft
 *         outputFormat: html   # or 'json' for a structured TipTap document
 *         height: 400px
 */
class BlockCraft extends FormWidgetBase
{
    /**
     * @var string outputFormat controls the persisted shape of the field's
     * value: 'html' (sanitized HTML string, the default - drop-in
     * replacement for `richeditor`) or 'json' (a structured TipTap document,
     * `{type: 'doc', content: [...]}`, for consumers that want to render the
     * content themselves rather than trusting stored HTML).
     */
    public $outputFormat = 'html';

    /**
     * @var string height is a CSS height value (e.g. "400px", "60vh")
     * applied to the editor's scrollable content area.
     */
    public $height = '400px';

    /**
     * @var array|null buttons restricts the visible toolbar buttons. Null
     * (the default) shows BlockCraft's full default toolbar. Recognised
     * names: bold, italic, strike, code, heading1..heading6, bulletList,
     * orderedList, blockquote, codeBlock, table, link, image,
     * horizontalRule, undo, redo.
     */
    public $buttons = null;

    /**
     * @var bool readOnly renders the editor content non-editable.
     */
    public $readOnly = false;

    /**
     * @inheritDoc
     */
    protected $defaultAlias = 'blockcraft';

    /**
     * @inheritDoc
     */
    public function init()
    {
        if ($this->formField->disabled) {
            $this->readOnly = true;
        }

        $this->fillFromConfig([
            'outputFormat',
            'height',
            'buttons',
            'readOnly',
        ]);

        if (!in_array($this->outputFormat, ['html', 'json'], true)) {
            $this->outputFormat = 'html';
        }
    }

    /**
     * @inheritDoc
     */
    public function render()
    {
        $this->prepareVars();

        (new ViteResolver)->inject($this, 'assets/js/blockcraft.ts');

        return $this->makePartial('blockcraft');
    }

    /**
     * prepareVars for display. The initial value and static config are
     * serialized as JSON and handed to the frontend hydrator via data-*
     * attributes on the mount element - the same attribute-encoding
     * approach verified (and hardened against HTML-attribute injection) in
     * the VueForge plugin's VueWidget::encodeProps().
     */
    public function prepareVars()
    {
        $value = $this->getLoadValue();

        if ($this->outputFormat === 'json') {
            $decoded = is_array($value) ? $value : json_decode((string) $value, true);
            $value = (new JsonSanitizer)->clean($decoded);
        } elseif (!is_string($value)) {
            $value = '';
        }

        $this->vars['fieldId'] = $this->getId();
        $this->vars['fieldName'] = $this->getFieldName();
        $this->vars['config'] = $this->encodeAttr([
            'outputFormat' => $this->outputFormat,
            'height' => $this->height,
            'buttons' => $this->buttons,
            'readOnly' => $this->readOnly,
            'useMediaManager' => BackendAuth::userHasAccess('media.library'),
        ]);
        $this->vars['value'] = $this->encodeAttr($value);
        $this->vars['readOnly'] = $this->readOnly;
    }

    /**
     * getSaveValue sanitizes the posted payload before it reaches the
     * Eloquent model - HTML mode strips anything outside HtmlSanitizer's
     * allow-list, JSON mode validates/cleans the TipTap document structure.
     * Malformed input degrades to a safe empty value; it never throws and
     * never reaches the database unsanitized.
     *
     * @param mixed $value
     * @return string|array
     */
    public function getSaveValue($value)
    {
        if ($this->formField->disabled || $this->formField->hidden) {
            return $this->previewMode ? $value : $this->getLoadValue();
        }

        if ($this->outputFormat === 'json') {
            $decoded = is_array($value) ? $value : json_decode((string) $value, true);

            return (new JsonSanitizer)->clean($decoded);
        }

        return (new HtmlSanitizer)->clean(is_string($value) ? $value : '');
    }

    /**
     * @inheritDoc
     */
    protected function loadAssets()
    {
        // BlockCraft's own CSS (toolbar chrome, ProseMirror content styles)
        // ships bundled with the JS entry via Vite (imported from
        // assets/js/blockcraft.ts) and is injected by ViteResolver alongside
        // the JS - nothing to add here directly.
    }

    /**
     * encodeAttr JSON-encodes a value and HTML-escapes the result for
     * embedding as a double-quoted HTML attribute. PHP's JSON_HEX_* flags
     * only escape characters *inside* JSON string values, not JSON's own
     * structural quotes, so an explicit htmlspecialchars() pass is required
     * - confirmed the hard way in VueForge's own build (see its
     * PROJECT_PROGRESS.md, Phase 2).
     *
     * @param mixed $value
     * @return string
     */
    protected function encodeAttr($value): string
    {
        return htmlspecialchars(
            json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
