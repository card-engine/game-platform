<?php
/**
 * Here is your custom functions.
 */

if (!function_exists('id2big')) {
    function id2big(int $id): int
    {
        if ($id === 0) return 0;
        $absolute = abs($id);
        if ($absolute > 150094635296998391) return 0;
        $value = strtr(str_pad(strrev(base_convert((string) ($absolute + 729), 10, 9)), 4, '0'), '012345678', '780162953');
        return strlen($value) > 18 ? 0 : (int) (($id < 0 ? '9' : '6') . $value);
    }
}

if (!function_exists('big2id')) {
    function big2id(int $value): int|false
    {
        $text = (string) $value;
        if (strlen($text) < 5 || str_contains($text, '4') || !ctype_digit($text) || !in_array($text[0], ['6', '9'], true)) return false;
        $payload = ltrim(strrev(strtr(substr($text, 1), '780162953', '012345678')), '0');
        if ($payload === '') return false;
        $id = ((int) base_convert($payload, 9, 10) - 729) * ($text[0] === '9' ? -1 : 1);
        return $id > 0 && id2big($id) === $value ? $id : false;
    }
}

if (!function_exists('game_platform_sign')) {
    function game_platform_sign(array $params, string $secret): string
    {
        unset($params['sign']);
        $params = array_map(static fn ($value) => $value ?? '', $params);
        ksort($params);
        return md5(http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '&secret=' . $secret);
    }
}

if (!function_exists('mg_no')) {
    function mg_no(string $prefix, ?string $month = null): string
    {
        return strtoupper($prefix) . ($month ?? gmdate('ym')) . gmdate('dHis') . bin2hex(random_bytes(6));
    }
}
