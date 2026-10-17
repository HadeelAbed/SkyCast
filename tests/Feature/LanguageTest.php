<?php

namespace Tests\Feature;

use Tests\TestCase;

class LanguageTest extends TestCase
{
    /** 12a. Switching to a supported locale stores it. */
    public function test_switching_to_a_supported_locale_stores_it(): void
    {
        $response = $this->from(route('weather.index'))->get(route('language.switch', 'ar'));

        $response->assertRedirect(route('weather.index'));
        $this->assertSame('ar', session('locale'));
    }

    /** 12b. Unsupported locales are rejected and never stored. */
    public function test_unsupported_locale_is_rejected(): void
    {
        $response = $this->get('/language/zz');

        $response->assertNotFound();
        $this->assertNull(session('locale'));
    }

    /** The stored locale drives the rendered direction. */
    public function test_arabic_locale_renders_rtl(): void
    {
        $this->withSession(['locale' => 'ar'])
            ->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false);
    }

    /** A tampered/unsupported session locale falls back to the default. */
    public function test_unsupported_session_locale_falls_back(): void
    {
        $this->withSession(['locale' => 'zz'])
            ->get('/')
            ->assertOk()
            ->assertSee('dir="ltr"', false);
    }
}
