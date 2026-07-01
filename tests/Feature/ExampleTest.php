<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/login'));
});