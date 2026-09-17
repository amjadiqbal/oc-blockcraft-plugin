<?php

namespace AmjadIqbal\BlockCraft\Classes;

use Backend\Classes\FormWidgetBase;
use RuntimeException;

/**
 * Resolves Vite asset URLs for the BlockCraft entry point, transparently
 * switching between the local Vite HMR dev server and the production
 * manifest.json produced by `npm run build`. Mirrors the pattern already
 * verified in the VueForge plugin's own ViteResolver, adapted for
 * BlockCraft's single fixed entry point (no per-field component variants).
 */
class ViteResolver
{
    /**
     * Local dev server URL. BlockCraft's own vite.config.ts pins port 5174
     * (rather than VueForge's 5173) so both plugins' dev servers can run
     * side by side during development.
     */
    protected string $devServerUrl = 'http://localhost:5174';

    protected string $buildDir = 'assets/dist';

    protected string $manifestPath = 'assets/dist/.vite/manifest.json';

    protected ?array $manifestCache = null;

    /**
     * Inject the JS/CSS needed to load $entry (e.g. "assets/js/blockcraft.ts")
     * onto the given form widget, in either dev or production mode.
     */
    public function inject(FormWidgetBase $widget, string $entry): void
    {
        if ($this->isDevServerRunning()) {
            $this->injectDev($widget, $entry);

            return;
        }

        $this->injectProduction($widget, $entry);
    }

    /**
     * Detect whether the Vite dev server is reachable. A short timeout keeps
     * a cold/absent dev server from ever stalling a production page load -
     * this should only ever resolve true on a developer machine.
     */
    public function isDevServerRunning(): bool
    {
        if (!app()->environment('local', 'testing')) {
            return false;
        }

        $context = stream_context_create(['http' => ['timeout' => 0.2, 'ignore_errors' => true]]);
        $result = @file_get_contents($this->devServerUrl . '/@vite/client', false, $context);

        return $result !== false;
    }

    protected function injectDev(FormWidgetBase $widget, string $entry): void
    {
        $widget->addJs($this->devServerUrl . '/@vite/client', ['type' => 'module']);
        $widget->addJs($this->devServerUrl . '/' . ltrim($entry, '/'), ['type' => 'module']);
    }

    protected function injectProduction(FormWidgetBase $widget, string $entry): void
    {
        $manifest = $this->loadManifest();
        $chunk = $manifest[$entry] ?? null;

        if (!$chunk) {
            throw new RuntimeException(sprintf(
                'BlockCraft: entry "%s" was not found in the Vite manifest (%s). Run `npm run build` in the blockcraft plugin directory.',
                $entry,
                $this->manifestPath
            ));
        }

        $baseUrl = '/plugins/amjadiqbal/blockcraft/' . $this->buildDir . '/';

        $widget->addJs($baseUrl . $chunk['file'], ['type' => 'module']);

        foreach ($chunk['css'] ?? [] as $cssFile) {
            $widget->addCss($baseUrl . $cssFile);
        }

        foreach ($chunk['imports'] ?? [] as $importKey) {
            $importedChunk = $manifest[$importKey] ?? null;
            if ($importedChunk) {
                $widget->addJs($baseUrl . $importedChunk['file'], ['type' => 'modulepreload']);
            }
        }
    }

    protected function loadManifest(): array
    {
        if ($this->manifestCache !== null) {
            return $this->manifestCache;
        }

        $path = plugins_path('amjadiqbal/blockcraft/' . $this->manifestPath);

        if (!file_exists($path)) {
            throw new RuntimeException(sprintf(
                'BlockCraft: Vite manifest not found at "%s". Run `npm run build` in the blockcraft plugin directory.',
                $path
            ));
        }

        $contents = json_decode(file_get_contents($path), true);

        return $this->manifestCache = is_array($contents) ? $contents : [];
    }
}
