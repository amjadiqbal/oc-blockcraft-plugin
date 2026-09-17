<?php

namespace AmjadIqbal\BlockCraft\Classes;

/**
 * JsonSanitizer validates and cleans a decoded TipTap JSON document before
 * it is persisted, when the form widget's `outputFormat` is `json`.
 *
 * TipTap's document schema (https://tiptap.dev/docs/editor/api/schema) is a
 * recursive node tree: `{ type: 'doc', content: Node[] }`, where each Node is
 * `{ type: string, attrs?: object, content?: Node[], text?: string, marks?: Mark[] }`
 * and each Mark is `{ type: string, attrs?: object }`. This sanitizer allow-
 * lists node/mark types to exactly what BlockCraft's own extension set can
 * produce (StarterKit + Image + Table), drops unknown attribute keys per
 * node/mark type, and never throws on malformed input - it degrades to an
 * empty document instead, matching getSaveValue()'s "never persist garbage,
 * never crash the save" contract.
 */
class JsonSanitizer
{
    /** @var array<string, string[]> node type => allowed attrs keys */
    protected array $allowedNodeAttrs = [
        'doc' => [],
        'paragraph' => [],
        'text' => [],
        'heading' => ['level'],
        'bulletList' => [],
        'orderedList' => ['start'],
        'listItem' => [],
        'blockquote' => [],
        'codeBlock' => ['language'],
        'horizontalRule' => [],
        'hardBreak' => [],
        'table' => [],
        'tableRow' => [],
        'tableCell' => ['colspan', 'rowspan', 'colwidth'],
        'tableHeader' => ['colspan', 'rowspan', 'colwidth'],
        'image' => ['src', 'alt', 'title', 'align'],
    ];

    /** @var array<string, string[]> mark type => allowed attrs keys */
    protected array $allowedMarkAttrs = [
        'bold' => [],
        'italic' => [],
        'strike' => [],
        'underline' => [],
        'code' => [],
        'link' => ['href', 'target', 'rel'],
    ];

    /**
     * clean validates $value as a TipTap document and returns a sanitized
     * copy, or an empty document if $value isn't a usable TipTap doc at all.
     *
     * @param mixed $value
     * @return array{type: string, content: array}
     */
    public function clean($value): array
    {
        if (!is_array($value) || ($value['type'] ?? null) !== 'doc') {
            return $this->emptyDocument();
        }

        $content = is_array($value['content'] ?? null) ? $value['content'] : [];

        return [
            'type' => 'doc',
            'content' => $this->cleanNodeList($content),
        ];
    }

    /**
     * @return array{type: string, content: array<int, mixed>}
     */
    public function emptyDocument(): array
    {
        return ['type' => 'doc', 'content' => []];
    }

    /**
     * @param mixed $nodes
     * @return array<int, array>
     */
    protected function cleanNodeList($nodes): array
    {
        if (!is_array($nodes)) {
            return [];
        }

        $clean = [];

        foreach ($nodes as $node) {
            $cleaned = $this->cleanNode($node);

            if ($cleaned !== null) {
                $clean[] = $cleaned;
            }
        }

        return $clean;
    }

    /**
     * @param mixed $node
     * @return array|null null when the node type isn't allow-listed at all.
     */
    protected function cleanNode($node): ?array
    {
        if (!is_array($node) || !is_string($node['type'] ?? null)) {
            return null;
        }

        $type = $node['type'];

        if (!array_key_exists($type, $this->allowedNodeAttrs)) {
            return null;
        }

        $clean = ['type' => $type];

        if ($type === 'text') {
            $clean['text'] = is_string($node['text'] ?? null) ? $node['text'] : '';

            if (!empty($node['marks']) && is_array($node['marks'])) {
                $marks = $this->cleanMarks($node['marks']);
                if ($marks) {
                    $clean['marks'] = $marks;
                }
            }

            return $clean;
        }

        if (!empty($node['attrs']) && is_array($node['attrs'])) {
            $attrs = $this->filterAttrs($node['attrs'], $this->allowedNodeAttrs[$type]);
            if ($attrs) {
                $clean['attrs'] = $attrs;
            }
        }

        if (!empty($node['content']) && is_array($node['content'])) {
            $clean['content'] = $this->cleanNodeList($node['content']);
        }

        return $clean;
    }

    /**
     * @param array $marks
     * @return array<int, array>
     */
    protected function cleanMarks(array $marks): array
    {
        $clean = [];

        foreach ($marks as $mark) {
            if (!is_array($mark) || !is_string($mark['type'] ?? null)) {
                continue;
            }

            $type = $mark['type'];

            if (!array_key_exists($type, $this->allowedMarkAttrs)) {
                continue;
            }

            $cleanMark = ['type' => $type];

            if (!empty($mark['attrs']) && is_array($mark['attrs'])) {
                $attrs = $this->filterAttrs($mark['attrs'], $this->allowedMarkAttrs[$type]);

                // A link mark with no usable href is worthless and a
                // potential no-op vector - drop the mark entirely.
                if ($type === 'link' && empty($attrs['href'])) {
                    continue;
                }

                if ($attrs) {
                    $cleanMark['attrs'] = $attrs;
                }
            } elseif ($type === 'link') {
                continue;
            }

            $clean[] = $cleanMark;
        }

        return $clean;
    }

    /**
     * @param array $attrs
     * @param string[] $allowedKeys
     * @return array<string, mixed>
     */
    protected function filterAttrs(array $attrs, array $allowedKeys): array
    {
        $clean = [];

        foreach ($attrs as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                continue;
            }

            // Only allow scalar attribute values - objects/resources can't
            // come from a legitimate TipTap doc.
            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            if (in_array($key, ['href', 'src'], true) && is_string($value) && !$this->isSafeUri($value)) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    protected function isSafeUri(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return true;
        }

        if (!preg_match('#^([a-zA-Z][a-zA-Z0-9+.-]*):#', $value, $matches)) {
            return true;
        }

        return in_array(strtolower($matches[1]), ['http', 'https', 'mailto', 'tel'], true);
    }
}
