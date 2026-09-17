<?php

namespace AmjadIqbal\BlockCraft;

use System\Classes\PluginBase;
use AmjadIqbal\BlockCraft\FormWidgets\BlockCraft as BlockCraftWidget;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name' => 'BlockCraft',
            'description' => 'The modern TipTap block editor & document builder for October CMS.',
            'author' => 'Amjad Iqbal',
            'icon' => 'icon-edit',
            'homepage' => 'https://github.com/amjadiqbal/oc-blockcraft-plugin',
        ];
    }

    public function registerFormWidgets()
    {
        return [
            BlockCraftWidget::class => [
                'label' => 'BlockCraft Editor',
                'code' => 'blockcraft',
            ],
        ];
    }
}
