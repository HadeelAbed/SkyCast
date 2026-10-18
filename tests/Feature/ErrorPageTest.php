<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The app ships branded HTTP error pages (404/405/419/429/500/503) so a stray
 * request never shows the raw Symfony "Oops! An Error Occurred" page.
 */
class ErrorPageTest extends TestCase
{
    public function test_a_missing_page_uses_the_branded_404_view(): void
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound();
        $response->assertSee('SkyCast');
        $response->assertSee('404');
        $response->assertDontSee('Oops');
    }

    public function test_a_wrong_method_uses_the_branded_405_view(): void
    {
        $response = $this->get('/weather/location');

        $response->assertStatus(405);
        $response->assertSee('405');
        $response->assertDontSee('Oops');
    }

    public function test_every_error_view_renders_with_its_code(): void
    {
        foreach ([404, 405, 419, 429, 500, 503] as $code) {
            $html = view("errors.{$code}")->render();

            $this->assertStringContainsString((string) $code, $html);
            $this->assertStringContainsString('SkyCast', $html);
        }
    }

    public function test_error_views_are_localized(): void
    {
        app()->setLocale('en');
        $this->assertStringContainsString('Page not found', view('errors.404')->render());

        app()->setLocale('ar');
        $this->assertStringContainsString('الصفحة غير موجودة', view('errors.404')->render());
    }
}
