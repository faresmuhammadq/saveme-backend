<?php

/*
| The gallery exists in the local environment only (spec 006, FR-041, SC-008).
*/

it('does not exist outside the local environment', function (string $environment) {
    registerAdminRoutesAs($environment);

    $this->get('/admin/_gallery')->assertNotFound();
})->with(['production', 'staging', 'testing']);

it('exists in the local environment', function () {
    registerAdminRoutesAs('local');

    $this->get('/admin/_gallery')->assertOk();
});
