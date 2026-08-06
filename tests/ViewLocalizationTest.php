<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Plugins\I18n\Support\Lang;
use Plugins\I18n\Translator;

/**
 * The tenancy admin screens must actually render translated copy.
 *
 * A catalogue existing is not the same as a view using it: a template still
 * holding a hard-coded sentence passes every catalogue test and still serves
 * English to a French operator.
 *
 * These views also embed user-facing strings inside inline JavaScript, which is
 * the case most likely to be missed — a sentence built by concatenation in JS
 * cannot be translated at all, because word order differs between languages.
 */
#[CoversNothing]
final class ViewLocalizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::clear();
    }

    /**
     * module.json declares the catalogue with "global": false, so the `tenancy`
     * NAMESPACE is the only route in — registering it as a global path would
     * resolve nothing and every key would render as itself.
     */
    private function bindLocale(string $locale): void
    {
        Lang::bind(new Translator(
            directory:  [],
            locale:     $locale,
            fallback:   'en',
            namespaces: ['tenancy' => [dirname(__DIR__) . '/resources/lang']],
        ));
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, array $data = []): string
    {
        $file = dirname(__DIR__) . '/resources/views/' . $view . '.php';
        self::assertFileExists($file);

        extract($data + ['title' => null, 'apiBase' => '/ajx', 'csrf' => 'tok', 'view' => ''], EXTR_SKIP);
        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    /** @return list<string> every view this plugin ships */
    private static function views(): array
    {
        return ['layouts/app', 'tenants/index', 'tenants/manage', 'tenants/create', 'tenants/edit', 'hosts/index'];
    }

    // --- Rendering ------------------------------------------------------------

    public function test_tenant_list_renders_french(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('tenants/index');

        $this->assertStringContainsString('Vos locataires', $html);
        $this->assertStringContainsString('Sélectionner', $html);
        $this->assertStringNotContainsString('Your tenants', $html);
    }

    public function test_provisioning_form_renders_french(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('tenants/create');

        $this->assertStringContainsString('Provisionner un nouveau locataire', $html);
        $this->assertStringContainsString('Mot de passe de la base de données', $html);
    }

    public function test_hosts_screen_renders_french(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('hosts/index');

        $this->assertStringContainsString('Ajouter un domaine personnalisé', $html);
        $this->assertStringContainsString('Publiez cet enregistrement DNS', $html);
    }

    public function test_layout_title_follows_the_locale(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('layouts/app');

        $this->assertStringContainsString('Locataires', $html);
        $this->assertStringContainsString('lang="fr"', $html);
    }

    // --- Strings inside inline JavaScript -------------------------------------

    /**
     * The flash messages were built by concatenating fragments in JS
     * ('Now scoped to ' + name + ' as ' + role). That shape cannot be
     * translated: the fragments have no meaning apart from English word order.
     * They are now emitted as a whole translated sentence with :placeholders
     * substituted client-side.
     */
    public function test_javascript_flash_messages_are_translated(): void
    {
        $this->bindLocale('fr');

        $index  = $this->render('tenants/index');
        $create = $this->render('tenants/create');

        $this->assertStringContainsString('Vous travaillez maintenant sur', $index);
        $this->assertStringContainsString("replace(':name'", $index);
        $this->assertStringNotContainsString("'Now scoped to '", $index);

        $this->assertStringContainsString('provisionn', $create);
        $this->assertStringNotContainsString('" provisioned.', $create);
    }

    /**
     * The message is emitted through json_encode, so an apostrophe or a quote in
     * a translation cannot terminate the JS string literal early and break the
     * page. French copy is full of apostrophes, so this is not hypothetical.
     */
    public function test_translated_javascript_strings_are_json_encoded(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('tenants/index');

        // A raw single-quoted PHP echo would have produced ...flash('Vous...
        // json_encode always emits a double-quoted, fully escaped literal.
        $this->assertMatchesRegularExpression('/flash\(\s*"/', $html);
    }

    // --- No leakage -----------------------------------------------------------

    public function test_no_english_copy_survives_a_french_render(): void
    {
        $this->bindLocale('fr');

        $html = '';
        foreach (self::views() as $v) {
            $html .= $this->render($v);
        }

        foreach ([
            '>Your tenants<',
            '>Provision a new tenant<',
            '>Add a custom domain<',
            '>No hosts registered yet.<',
            '>You are not a member of any tenant yet.<',
            '>Save changes<',
        ] as $english) {
            $this->assertStringNotContainsString($english, $html, "untranslated: {$english}");
        }
    }

    public function test_no_raw_translation_keys_leak_into_the_output(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $this->bindLocale($locale);

            $html = '';
            foreach (self::views() as $v) {
                $html .= $this->render($v);
            }

            $this->assertStringNotContainsString('tenancy::', $html, "[{$locale}] an unresolved key reached the output");
        }
    }

    /**
     * API endpoints shown in the UI are technical identifiers, not copy. If a
     * bulk replacement ever swept one into the catalogue the screen would
     * describe an endpoint that does not exist.
     */
    public function test_api_endpoints_are_not_translated(): void
    {
        $this->bindLocale('fr');

        $this->assertStringContainsString('/ajx/me/tenants', $this->render('tenants/index'));
        $this->assertStringContainsString('/ajx/tenant/hosts', $this->render('hosts/index'));
    }
}
