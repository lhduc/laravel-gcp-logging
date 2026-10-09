<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Lhduc\LaravelGcpLogging\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    private const MASK = '[REDACTED]';

    private function redactor(): Redactor
    {
        return new Redactor(['authorization', 'cookie', 'password', 'token', 'otp', 'pin', 'secret', 'signature', 'api_key']);
    }

    public function test_sensitive_keys_are_masked_at_any_depth_and_case(): void
    {
        $out = $this->redactor()->redact([
            'Authorization' => ['Bearer abc'],
            'cookie' => 'a=b',
            'body' => ['user' => ['PASSWORD' => 'p', 'name' => 'bob'], 'otp' => 123456],
        ]);

        $this->assertSame(self::MASK, $out['Authorization']);
        $this->assertSame(self::MASK, $out['cookie']);
        $this->assertSame(self::MASK, $out['body']['user']['PASSWORD']);
        $this->assertSame('bob', $out['body']['user']['name']);
        $this->assertSame(self::MASK, $out['body']['otp']);
    }

    public function test_keys_match_by_word_not_by_substring(): void
    {
        $r = $this->redactor();

        foreach (['user_password', 'newPassword', 'access_token', 'X-Api-Key', 'otp_code', 'passwords', 'pins', 'x-csrf-token'] as $key) {
            $this->assertTrue($r->isSensitiveKey($key), $key);
        }

        foreach (['shipping', 'footprint', 'mapping', 'pinned', 'tokenizer', 'name', 'status', 'api'] as $key) {
            $this->assertFalse($r->isSensitiveKey($key), $key);
        }
    }

    public function test_null_values_and_non_sensitive_data_are_untouched(): void
    {
        $data = ['password' => null, 'items' => [1, 2], 'ok' => true, 'n' => 1.5];

        $this->assertSame($data, $this->redactor()->redact($data));
    }

    public function test_json_text_is_masked_including_truncated_and_escaped_forms(): void
    {
        $r = $this->redactor();

        $plain = $r->redact('{"email":"a@b.c","password":"p\"w","nested":{"token":"abc","n":1},"otp":123456}');
        $this->assertSame('{"email":"a@b.c","password":"[REDACTED]","nested":{"token":"[REDACTED]","n":1},"otp":"[REDACTED]"}', $plain);

        // Queue payloads: JSON inside a JSON string.
        $escaped = $r->redact('{"body":"{\"token\":\"zzz\",\"ok\":1}"}');
        $this->assertStringNotContainsString('zzz', $escaped);
        $this->assertStringContainsString('\"ok\":1', $escaped);
    }

    public function test_php_serialized_text_is_masked(): void
    {
        $r = $this->redactor();

        $out = $r->redact('a:2:{s:8:"password";s:6:"hunter";s:4:"name";s:3:"bob";}');
        $this->assertStringNotContainsString('hunter', $out);
        $this->assertStringContainsString('s:4:"name";s:3:"bob";', $out);

        // inside a queue payload JSON the quotes are escaped
        $escaped = $r->redact('{"command":"O:3:\"Job\":1:{s:8:\"password\";s:6:\"hunter\";}"}');
        $this->assertStringNotContainsString('hunter', $escaped);
    }

    public function test_query_strings_and_form_bodies_are_masked(): void
    {
        $r = $this->redactor();

        $this->assertSame(
            'https://x.test/cb?code=1&token=[REDACTED]&api_key=[REDACTED]&page=2&signature=[REDACTED]',
            $r->redact('https://x.test/cb?code=1&token=abc&api_key=KEY&page=2&signature=sig')
        );
        $this->assertSame('username=bob&password=[REDACTED]&remember=1', $r->redact('username=bob&password=hunter2&remember=1'));
    }

    public function test_text_without_sensitive_keys_is_untouched(): void
    {
        $text = 'Order shipped to footprint pinned item, page=2';

        $this->assertSame($text, $this->redactor()->redact($text));
    }

    public function test_empty_key_list_disables_redaction(): void
    {
        $data = ['password' => 'x', 'note' => '{"token":"y"}'];

        $this->assertSame($data, (new Redactor([]))->redact($data));
    }
}
