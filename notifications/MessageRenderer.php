<?php

/**
 * Pure {token} template renderer for notifications (Phase 9).
 *
 * Mirrors the Conversion-API template semantics: {token} placeholders are
 * replaced from a flat token map; unknown tokens are left untouched. No I/O —
 * fully unit-testable.
 */
class MessageRenderer
{
    /**
     * @param array<string,scalar|null> $tokens
     */
    public static function render(string $template, array $tokens): string
    {
        if ($template === '' || strpos($template, '{') === false) {
            return $template;
        }
        return preg_replace_callback('/\{([a-zA-Z0-9_.:-]+)\}/', static function (array $m) use ($tokens): string {
            $key = $m[1];
            if (array_key_exists($key, $tokens)) {
                $v = $tokens[$key];
                return is_scalar($v) ? (string)$v : (string)json_encode($v);
            }
            return $m[0];
        }, $template);
    }

    /**
     * Flatten a nested array into dot-notation token keys, e.g.
     * ['metrics' => ['roi' => 5]] => ['metrics.roi' => 5]. Scalar leaves only.
     *
     * @param array<string,mixed> $data
     * @return array<string,scalar|null>
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += self::flatten($v, $key);
            } elseif (is_scalar($v) || $v === null) {
                $out[$key] = $v;
            }
        }
        return $out;
    }
}
