<?php

namespace Lhduc\LaravelGcpLogging\Support;

/**
 * Masks sensitive values (passwords, tokens, auth headers...) in log payloads.
 *
 * A key is sensitive when its words contain a configured entry, so `user_password`,
 * `accessToken` and `X-Api-Key` match `password` / `token` / `api_key`, while
 * `shipping` or `footprint` do not match `pin` / `otp`.
 * Strings are scanned too (JSON text, PHP-serialized text, `?token=...` query strings)
 * because bodies and job payloads are often logged as text.
 */
class Redactor
{
    public const MASK = '[REDACTED]';

    private const MAX_DEPTH = 12;

    /** @var array<int, string[]> configured entries split into words */
    private array $entries = [];

    /** @var array<string, bool> */
    private array $keyCache = [];

    private ?string $prefilter = null;

    /**
     * @param  string[]  $keys
     */
    public function __construct(array $keys)
    {
        $words = [];

        foreach ($keys as $key) {
            $entry = $this->words((string) $key);
            if ($entry !== []) {
                $this->entries[] = $entry;
                array_push($words, ...$entry);
            }
        }

        if ($words !== []) {
            $this->prefilter = '/' . implode('|', array_map(fn ($w) => preg_quote($w, '/'), array_unique($words))) . '/i';
        }
    }

    public function redact(mixed $value, int $depth = 0): mixed
    {
        if ($this->prefilter === null) {
            return $value;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return $value;
            }

            foreach ($value as $key => $item) {
                $value[$key] = (is_string($key) && $item !== null && $this->isSensitiveKey($key))
                    ? self::MASK
                    : $this->redact($item, $depth + 1);
            }

            return $value;
        }

        return is_string($value) ? $this->redactString($value) : $value;
    }

    public function isSensitiveKey(string $key): bool
    {
        if (isset($this->keyCache[$key])) {
            return $this->keyCache[$key];
        }

        $keyWords = $this->words($key);
        $found = false;

        foreach ($this->entries as $entry) {
            if ($this->containsSequence($keyWords, $entry)) {
                $found = true;
                break;
            }
        }

        return $this->keyCache[$key] = $found;
    }

    private function redactString(string $text): string
    {
        if ($text === '' || ! preg_match($this->prefilter, $text)) {
            return $text;
        }

        // JSON text: "key": "value" | "key": 123
        $text = preg_replace_callback(
            '/("([^"\\\\]{1,100})"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|[^,}\]\s"]+)/s',
            fn ($m) => $this->isSensitiveKey($m[2]) ? $m[1] . '"' . self::MASK . '"' : $m[0],
            $text
        ) ?? $text;

        // JSON escaped inside another JSON string (queue payloads): \"key\":\"value\"
        $text = preg_replace_callback(
            '/(\\\\"([^"\\\\]{1,100})\\\\"\s*:\s*)(\\\\"(?:(?!\\\\").)*\\\\"|[^,}\]\s"\\\\]+)/s',
            fn ($m) => $this->isSensitiveKey($m[2]) ? $m[1] . '\\"' . self::MASK . '\\"' : $m[0],
            $text
        ) ?? $text;

        // PHP-serialized text: s:8:"password";s:3:"abc"; (also JSON-escaped: s:8:\"password\";...)
        $text = preg_replace_callback(
            '/s:(\d+):(\\\\*)"([^"\\\\]{1,100})\2";s:\d+:\2"(.*?)\2";/s',
            fn ($m) => $this->isSensitiveKey($m[3])
                ? 's:' . $m[1] . ':' . $m[2] . '"' . $m[3] . $m[2] . '";s:' . strlen(self::MASK) . ':' . $m[2] . '"' . self::MASK . $m[2] . '";'
                : $m[0],
            $text
        ) ?? $text;

        // Query strings / form bodies: ?token=abc&otp=123
        return preg_replace_callback(
            '/([?&;]|^)([^=&#\s?"\']{1,100})=([^&#\s"\']*)/',
            fn ($m) => $this->isSensitiveKey($m[2]) ? $m[1] . $m[2] . '=' . self::MASK : $m[0],
            $text
        ) ?? $text;
    }

    /**
     * Lower-cased words of a key: `accessToken`, `access_token`, `X-Access-Token` => [access, token].
     * A trailing plural "s" is dropped (`passwords` => `password`).
     *
     * @return string[]
     */
    private function words(string $key): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
        $parts = preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(
            fn (string $w) => strlen($w) > 3 && str_ends_with($w, 's') ? substr($w, 0, -1) : $w,
            $parts
        );
    }

    /**
     * @param  string[]  $haystack
     * @param  string[]  $needle
     */
    private function containsSequence(array $haystack, array $needle): bool
    {
        $n = count($needle);

        for ($i = 0, $max = count($haystack) - $n; $i <= $max; $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }
}
