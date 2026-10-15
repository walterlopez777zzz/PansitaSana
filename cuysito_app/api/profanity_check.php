<?php
/**
 * profanity_check.php — Server-side profanity detection for public display names.
 *
 * Provides two public functions:
 *   - validatePublicNameChars(string $name): array   — whitelist character check
 *   - containsProfanity(string $name): array         — full profanity pipeline
 *
 * Usage:
 *   require_once __DIR__ . '/profanity_check.php';
 *   $charResult = validatePublicNameChars($name);
 *   if (!$charResult['ok']) { /* reject with $charResult['reason'] *​/ }
 *   $profResult = containsProfanity($name);
 *   if ($profResult['found']) { /* reject with $profResult['reason'] *​/ }
 */

// ═══════════════════════════════════════════════════════════════════
// 1. CHARACTER WHITELIST VALIDATION
// ═══════════════════════════════════════════════════════════════════

/**
 * Validates that a public display name contains only allowed characters
 * and meets structural requirements.
 *
 * Allowed: a-z, A-Z, 0-9, space, underscore, period
 * 
 * @param string $name Raw input name
 * @return array ['ok' => bool, 'reason' => string|null]
 */
function validatePublicNameChars(string $name): array {
    $name = trim($name);

    // Length bounds
    if (mb_strlen($name) < 3) {
        return ['ok' => false, 'reason' => 'El nombre debe tener al menos 3 caracteres.'];
    }
    if (mb_strlen($name) > 20) {
        return ['ok' => false, 'reason' => 'El nombre no puede superar los 20 caracteres.'];
    }

    // Strip invisible / zero-width characters first
    $name = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\x{00AD}\x{034F}\x{061C}\x{180E}]/u', '', $name);

    // Only allow: a-z A-Z 0-9 space _ .
    if (!preg_match('/^[a-zA-Z0-9 _.]+$/', $name)) {
        return ['ok' => false, 'reason' => 'Solo se permiten letras (a-z), números, espacios, puntos y guiones bajos.'];
    }

    // Must contain at least one letter
    if (!preg_match('/[a-zA-Z]/', $name)) {
        return ['ok' => false, 'reason' => 'El nombre debe contener al menos una letra.'];
    }

    // No leading/trailing spaces or dots
    if (preg_match('/^[\s.]|[\s.]$/', $name)) {
        return ['ok' => false, 'reason' => 'El nombre no puede empezar o terminar con espacio o punto.'];
    }

    // No consecutive spaces
    if (preg_match('/\s{2,}/', $name)) {
        return ['ok' => false, 'reason' => 'El nombre no puede tener espacios consecutivos.'];
    }

    // No consecutive dots
    if (strpos($name, '..') !== false) {
        return ['ok' => false, 'reason' => 'El nombre no puede tener puntos consecutivos.'];
    }

    // No consecutive underscores
    if (strpos($name, '__') !== false) {
        return ['ok' => false, 'reason' => 'El nombre no puede tener guiones bajos consecutivos.'];
    }

    // Not all same character repeated
    if (preg_match('/^(.)\1+$/', preg_replace('/[\s_.]+/', '', strtolower($name)))) {
        return ['ok' => false, 'reason' => 'El nombre no puede ser un solo carácter repetido.'];
    }

    return ['ok' => true, 'reason' => null];
}


// ═══════════════════════════════════════════════════════════════════
// 2. PROFANITY DETECTION PIPELINE
// ═══════════════════════════════════════════════════════════════════

/**
 * Full profanity detection with multi-layer normalization.
 *
 * Pipeline:
 *   1. Strip invisible chars
 *   2. Lowercase
 *   3. Transliterate accented chars (á→a, ñ→n, etc.)
 *   4. Leetspeak decode (0→o, 1→i, 3→e, 4→a, 5→s, etc.)
 *   5. Phonetic substitutions (ph→f, ck→k, kk→k, sh→s)
 *   6. Collapse repeated chars (puuutaaa→puta)
 *   7. Generate variants: with separators stripped AND with separators as word boundaries
 *   8. Substring search against dictionary
 *
 * @param string $name Raw input name
 * @return array ['found' => bool, 'reason' => string|null]
 */
function containsProfanity(string $name): array {
    static $dictionary = null;
    if ($dictionary === null) {
        $listPath = __DIR__ . '/profanity_list.php';
        if (!file_exists($listPath)) {
            error_log('profanity_check: profanity_list.php not found at ' . $listPath);
            return ['found' => false, 'reason' => null];
        }
        $dictionary = require $listPath;
        if (!is_array($dictionary)) {
            error_log('profanity_check: profanity_list.php did not return an array');
            $dictionary = [];
        }
        // Pre-process dictionary: normalize each entry the same way
        $dictionary = array_map(function($word) {
            return _profanity_normalize_full($word);
        }, $dictionary);
        // Remove empties and deduplicate
        $dictionary = array_values(array_unique(array_filter($dictionary)));
    }

    if (empty($dictionary)) {
        return ['found' => false, 'reason' => null];
    }

    // Generate all normalized variants of the input
    $variants = _profanity_generate_variants($name);

    foreach ($variants as $variant) {
        foreach ($dictionary as $badWord) {
            if ($badWord === '') continue;
            // For very short banned words (1-3 chars), require exact match to avoid false positives
            // e.g. 'hp', 'ctm', 'kys' must be the entire name, not a substring
            if (mb_strlen($badWord) <= 3) {
                if ($variant === $badWord) {
                    return ['found' => true, 'reason' => 'El nombre contiene lenguaje inapropiado.'];
                }
            } else {
                // Substring match for 4+ char banned words (specific enough to avoid false positives)
                if (strpos($variant, $badWord) !== false) {
                    return ['found' => true, 'reason' => 'El nombre contiene lenguaje inapropiado.'];
                }
            }
        }
    }

    return ['found' => false, 'reason' => null];
}


// ═══════════════════════════════════════════════════════════════════
// INTERNAL: Normalization helpers
// ═══════════════════════════════════════════════════════════════════

/**
 * Generates multiple normalized variants of the input to catch evasion attempts.
 * Returns an array of strings to check against the dictionary.
 */
function _profanity_generate_variants(string $text): array {
    $variants = [];

    // Base normalization (always applied)
    $base = _profanity_normalize_full($text);

    // Variant 1: fully collapsed (no separators at all)
    // Catches: p.u.t.a, p_u_t_a, p u t a
    $collapsed = preg_replace('/[\s_.\-]+/', '', $base);
    $variants[] = $collapsed;

    // Variant 2: separators replaced by single space (word boundary detection)
    // Catches: normal words like "stupid name" 
    $spaced = preg_replace('/[\s_.\-]+/', ' ', $base);
    $variants[] = $spaced;

    // Variant 3: the base normalization itself
    $variants[] = $base;

    // Variant 4: collapsed with repeated chars reduced
    $variants[] = _profanity_collapse_repeats($collapsed);

    // Variant 5: spaced with repeated chars reduced
    $variants[] = _profanity_collapse_repeats($spaced);

    // Variant 6: only letters (strip ALL non-alpha)
    $alphaOnly = preg_replace('/[^a-z]/', '', $base);
    $variants[] = $alphaOnly;
    $variants[] = _profanity_collapse_repeats($alphaOnly);

    // Variant 7: reversed string (catches backward spelling like "kcuf")
    $variants[] = strrev($collapsed);
    $variants[] = _profanity_collapse_repeats(strrev($collapsed));

    // Remove empties and deduplicate
    return array_values(array_unique(array_filter($variants)));
}

/**
 * Full normalization pipeline for a single string.
 */
function _profanity_normalize_full(string $text): string {
    // Step 1: Strip invisible characters
    $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\x{00AD}\x{034F}\x{061C}\x{180E}]/u', '', $text);

    // Step 2: Lowercase
    $text = mb_strtolower($text, 'UTF-8');

    // Step 3: Transliterate accented/special chars to ASCII equivalents
    $text = _profanity_transliterate($text);

    // Step 4: Leetspeak decode
    $text = _profanity_decode_leetspeak($text);

    // Step 5: Phonetic substitutions
    $text = _profanity_phonetic_subs($text);

    // Step 6: Collapse repeated characters
    $text = _profanity_collapse_repeats($text);

    return $text;
}

/**
 * Transliterate accented and special characters to their ASCII base.
 */
function _profanity_transliterate(string $text): string {
    $map = [
        // Spanish / Latin accented vowels
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        // Spanish ñ
        'ñ' => 'n',
        // Other Latin consonants
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ð' => 'd', 'ď' => 'd',
        'ğ' => 'g',
        'ĺ' => 'l', 'ľ' => 'l', 'ł' => 'l',
        'ń' => 'n', 'ň' => 'n', 'ŋ' => 'n',
        'ŕ' => 'r', 'ř' => 'r',
        'ś' => 's', 'š' => 's', 'ş' => 's',
        'ť' => 't', 'ţ' => 't',
        'ź' => 'z', 'ž' => 'z', 'ż' => 'z',
        'þ' => 'th', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
        // Common Cyrillic lookalikes (homoglyph attacks)
        'а' => 'a', // Cyrillic а
        'е' => 'e', // Cyrillic е
        'о' => 'o', // Cyrillic о
        'р' => 'p', // Cyrillic р (looks like p)
        'с' => 'c', // Cyrillic с
        'у' => 'y', // Cyrillic у
        'х' => 'x', // Cyrillic х
        'і' => 'i', // Cyrillic і
        'ј' => 'j', // Cyrillic ј
        'ѕ' => 's', // Cyrillic ѕ
        // Greek lookalikes
        'α' => 'a', 'β' => 'b', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e',
        'η' => 'h', 'θ' => 'th', 'ι' => 'i', 'κ' => 'k', 'λ' => 'l',
        'μ' => 'm', 'ν' => 'n', 'ο' => 'o', 'π' => 'p', 'ρ' => 'r',
        'σ' => 's', 'τ' => 't', 'υ' => 'u', 'φ' => 'f', 'ω' => 'o',
        // Common symbol substitutions used in names
        '@' => 'a',
        '$' => 's',
        '!' => 'i',
        '¡' => 'i',
        '€' => 'e',
        '£' => 'l',
        '¥' => 'y',
        '©' => 'c',
        '®' => 'r',
    ];

    return strtr($text, $map);
}

/**
 * Decode leetspeak and numeric substitutions.
 */
function _profanity_decode_leetspeak(string $text): string {
    $map = [
        '0'  => 'o',
        '1'  => 'i',
        '2'  => 'z',
        '3'  => 'e',
        '4'  => 'a',
        '5'  => 's',
        '6'  => 'g',
        '7'  => 't',
        '8'  => 'b',
        '9'  => 'g',
        '|'  => 'i',
        '/'  => 'l',
        '\\' => 'l',
        '{'  => 'c',
        '}'  => 'j',
        '('  => 'c',
        ')'  => 'j',
        '<'  => 'c',
        '>'  => 'j',
        '+'  => 't',
        '='  => 'e',
        '#'  => 'h',
        '*'  => 'x',
        '~'  => 'n',
        '^'  => 'a',
    ];

    return strtr($text, $map);
}

/**
 * Apply common phonetic substitutions that evaders use.
 */
function _profanity_phonetic_subs(string $text): string {
    $replacements = [
        'ph'  => 'f',
        'ck'  => 'k',
        'kk'  => 'k',
        'cc'  => 'k',
        'qu'  => 'k',
        'wh'  => 'w',
        'gh'  => 'g',
        'wr'  => 'r',
        'kn'  => 'n',
        'mb'  => 'm',
        'x'   => 'ks',
        'z'   => 's',
    ];

    // Apply multi-char substitutions (order matters: longer first)
    foreach ($replacements as $from => $to) {
        $text = str_replace($from, $to, $text);
    }

    return $text;
}

/**
 * Collapse repeated characters to single.
 * puuutaaa → puta, fuuuuck → fuck, ssshit → shit
 */
function _profanity_collapse_repeats(string $text): string {
    return preg_replace('/(.)\1+/', '$1', $text);
}
