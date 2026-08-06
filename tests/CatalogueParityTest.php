<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every locale must define the same keys, with the same placeholders and the
 * same number of plural forms, as the reference locale.
 *
 * Translations rot silently: someone adds a message in English, the French file
 * is not updated, and French users are served the raw key
 * ('tenancy::tenancy.nav.brand') in an email. Nothing throws — the Translator
 * deliberately never does — so the gap survives until a user reports it.
 *
 * A dropped placeholder is worse, because the sentence still reads correctly
 * with the value missing. "Il expire dans minutes." looks like a typo rather
 * than a bug, and an OTP mail that never states its expiry is a support ticket.
 */
#[CoversNothing]
final class CatalogueParityTest extends TestCase
{
    private const REFERENCE = 'en';

    private static function langRoot(): string
    {
        return dirname(__DIR__) . '/resources/lang';
    }

    /** @return list<string> */
    private static function locales(): array
    {
        $found = [];
        foreach (glob(self::langRoot() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $found[] = basename($dir);
        }
        sort($found);

        return $found;
    }

    /**
     * @param array<mixed> $data
     * @return array<string,scalar>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /** @return array<string,scalar> */
    private static function catalogue(string $locale): array
    {
        $flat = [];
        foreach (glob(self::langRoot() . '/' . $locale . '/*.php') ?: [] as $file) {
            $data = require $file;
            if (is_array($data)) {
                $flat += self::flatten($data, basename($file, '.php'));
            }
        }

        return $flat;
    }

    /** @return list<string> */
    private static function placeholders(string $line): array
    {
        preg_match_all('/:([A-Za-z][A-Za-z0-9_]*)/', $line, $m);
        $found = array_map('strtolower', $m[1]);
        sort($found);

        return array_values(array_unique($found));
    }

    public function test_the_reference_catalogue_loads(): void
    {
        // Guards the test itself — a wrong path would make everything below
        // pass vacuously.
        $this->assertNotEmpty(self::catalogue(self::REFERENCE));
    }

    public function test_french_is_shipped(): void
    {
        $this->assertContains('fr', self::locales());
    }

    public function test_every_locale_defines_every_reference_key(): void
    {
        $reference = self::catalogue(self::REFERENCE);

        foreach (self::locales() as $locale) {
            if ($locale === self::REFERENCE) {
                continue;
            }

            $missing = array_diff(array_keys($reference), array_keys(self::catalogue($locale)));

            $this->assertSame([], array_values($missing), sprintf(
                '[%s] is missing: %s — these render as the raw key in a user-facing screen.',
                $locale,
                implode(', ', $missing),
            ));
        }
    }

    public function test_no_locale_defines_an_orphan_key(): void
    {
        $reference = self::catalogue(self::REFERENCE);

        foreach (self::locales() as $locale) {
            if ($locale === self::REFERENCE) {
                continue;
            }

            $extra = array_diff(array_keys(self::catalogue($locale)), array_keys($reference));

            $this->assertSame([], array_values($extra), sprintf(
                '[%s] defines keys [%s] does not: %s — usually a rename left behind.',
                $locale,
                self::REFERENCE,
                implode(', ', $extra),
            ));
        }
    }

    public function test_every_translation_keeps_the_reference_placeholders(): void
    {
        $reference = self::catalogue(self::REFERENCE);

        foreach (self::locales() as $locale) {
            if ($locale === self::REFERENCE) {
                continue;
            }

            $translated = self::catalogue($locale);

            foreach ($reference as $key => $line) {
                if (!isset($translated[$key])) {
                    continue;
                }

                $this->assertSame(
                    self::placeholders((string) $line),
                    self::placeholders((string) $translated[$key]),
                    sprintf(
                        '[%s] "%s" has different placeholders. Reference: "%s" / translated: "%s".',
                        $locale,
                        $key,
                        $line,
                        $translated[$key],
                    ),
                );
            }
        }
    }

    /**
     * A plural line whose translation has fewer forms silently serves the wrong
     * one — trans_choice() falls back to the first segment — so "1 minute" would
     * be shown for every count.
     */
    public function test_plural_lines_keep_the_same_number_of_forms(): void
    {
        $reference = self::catalogue(self::REFERENCE);
        $checked   = 0;

        foreach (self::locales() as $locale) {
            if ($locale === self::REFERENCE) {
                continue;
            }

            $translated = self::catalogue($locale);

            foreach ($reference as $key => $line) {
                if (!isset($translated[$key]) || !str_contains((string) $line, '|')) {
                    continue;
                }

                $checked++;
                $this->assertSame(
                    substr_count((string) $line, '|'),
                    substr_count((string) $translated[$key], '|'),
                    sprintf('[%s] "%s" has a different number of plural forms.', $locale, $key),
                );
            }
        }

        // A catalogue with no plural lines is a legitimate state, but it must
        // be asserted rather than left as a test that silently checks nothing —
        // otherwise this stays green if the catalogue path ever breaks and
        // every locale loads empty.
        if ($checked === 0) {
            $this->assertNotEmpty($reference, 'the reference catalogue must still have loaded');
        }
    }
}
