<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

/**
 * Default notification renderer (BR-COM-03 FR-COM-03-003).
 *
 * Renders a stored inbox row through its registered type schema:
 *   - body: [label => sprintf-pattern] substituted from the row's payload;
 *   - link: href + ?id_param=<payload[id_from]> when both are configured;
 *   - a registered callable renderer replaces the default output entirely.
 *
 * Silence is safe: an unregistered type or any renderer failure degrades to a
 * raw JSON dump of the payload — never throws.
 *
 * Rendered output:
 *   ['title' => string,
 *    'body'  => string|array,           // callable result or body lines
 *    'link'  => string|null,
 *    'icon'  => string|null,
 *    'raw'   => array]                  // original payload
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class NotificationTypeRenderer
{
    /** @var NotificationTypeRegistry */
    private $registry;

    public function __construct(NotificationTypeRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param array $row stored inbox row (recipient_uid/type/payload/ref/...)
     *
     * @return array<string,mixed>
     */
    public function render(array $row): array
    {
        $type    = (string) ($row['type'] ?? '');
        $payload = isset($row['payload']) && \is_array($row['payload'])
            ? $row['payload']
            : (\json_decode((string) ($row['payload'] ?? '{}'), true) ?: []);
        $raw     = $payload;

        $definition = $type !== '' ? $this->registry->get($type) : null;
        if ($definition === null) {
            return $this->fallback($type, $payload);
        }

        if ($definition['renderer'] !== null) {
            try {
                $out = ($definition['renderer'])($payload, $row);
                if (!\is_array($out)) {
                    return $this->fallback($type, $payload);
                }
                return \array_merge([
                    'title' => (string) ($out['title'] ?? $definition['title']),
                    'body'  => $out['body'] ?? null,
                    'link'  => isset($out['link']) ? (string) $out['link'] : null,
                    'icon'  => isset($out['icon']) ? (string) $out['icon'] : $definition['icon'],
                    'raw'   => $raw,
                ], \is_array($out) ? $out : []);
            } catch (\Throwable $e) {
                return $this->fallback($type, $payload);
            }
        }

        return [
            'title' => (string) $definition['title'],
            'body'  => $this->renderBody((array) $definition['body'], $payload),
            'link'  => $this->renderLink((array) $definition['link'], $payload),
            'icon'  => $definition['icon'],
            'raw'   => $raw,
        ];
    }

    /**
     * @return array<int,array{label:string,text:string}>|null
     */
    private function renderBody(array $schema, array $payload): ?array
    {
        if ($schema === []) {
            return null;
        }
        $lines = [];
        foreach ($schema as $label => $pattern) {
            $text = (string) $pattern;
            // substitute %s once per payload field name found in the pattern
            foreach ($payload as $key => $value) {
                $needle = '%' . $key . '%';
                if ($value !== null && \strpos($text, $needle) !== false) {
                    $text = \str_replace($needle, $value, $text);
                }
            }
            if (\strpos($text, '%s') !== false) {
                $first = null;
                foreach ($payload as $value) {
                    if (\is_scalar($value) || $value === null) {
                        $first = $value;
                        break;
                    }
                }
                $text = \sprintf($text, $first);
            }
            $lines[] = ['label' => (string) $label, 'text' => $text];
        }
        return \array_values(\array_filter($lines, function ($line): bool {
            return $line['text'] !== '';
        }));
    }

    private function renderLink(array $schema, array $payload): ?string
    {
        $href    = isset($schema['href']) ? (string) $schema['href'] : '';
        $idParam = isset($schema['id_param']) ? (string) $schema['id_param'] : '';
        $idFrom  = isset($schema['id_from']) ? (string) $schema['id_from'] : 'id';

        if ($href === '' || $idParam === '') {
            return $href !== '' ? $href : null;
        }
        $idValue = isset($payload[$idFrom]) ? (string) $payload[$idFrom] : '';
        if ($idValue === '') {
            return $href;
        }
        $sep = \strpos($href, '?') !== false ? '&' : '?';
        return $href . $sep . $idParam . '=' . \rawurlencode($idValue);
    }

    /**
     * @return array<string,mixed>
     */
    private function fallback(string $type, array $payload): array
    {
        return [
            'title' => $type !== '' ? $type : 'notification',
            'body'  => \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'link'  => null,
            'icon'  => null,
            'raw'   => $payload,
        ];
    }
}