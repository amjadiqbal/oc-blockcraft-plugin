<?php if ($this->previewMode): ?>
    <div class="form-control">
        <?php if ($this->outputFormat === 'json'): ?>
            <pre><?= e(json_encode($this->getLoadValue())) ?></pre>
        <?php else: ?>
            <?= $this->getLoadValue() ?>
        <?php endif ?>
    </div>
<?php else: ?>
    <div
        id="<?= $fieldId ?>"
        class="blockcraft-widget"
        data-control="blockcraft"
        data-blockcraft-config="<?= $config ?>"
        data-blockcraft-value="<?= $value ?>"
        <?php if ($readOnly): ?>data-blockcraft-readonly="true"<?php endif ?>
    ></div>
    <textarea
        name="<?= $fieldName ?>"
        id="<?= $fieldId ?>-input"
        data-blockcraft-hidden-input
        style="display: none"
    ></textarea>
<?php endif ?>
