<?php

/*
 * The mailed invitation link opens a page that hands the token to the app
 * (spec 016, FR-005). The page never previews, accepts or spends anything.
 */

test('opening the mailed invitation link opens the app through an intent link carrying the token', function () {
    $this->get('/invite?token=abc_DEF-123')
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('intent://saveme-backend.test/invite?token=abc_DEF-123#Intent;scheme=http;package=com.fares.saveme;end', false);
});

test('opening an invitation link without a token shows the invalid page', function () {
    $this->get('/invite')->assertStatus(410);
    $this->get('/invite?token='.str_repeat('a', 129))->assertStatus(410);
});
