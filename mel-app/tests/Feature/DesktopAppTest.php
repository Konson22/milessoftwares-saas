<?php

/*
 * The desktop app (ADR-0019 §23): installable, with a service worker that
 * caches static files only (resources/js/lib/service-worker.test.ts runs it).
 */

it('describes the installable app', function () {
    $manifest = json_decode((string) file_get_contents(public_path('mel/manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toMatchArray(['name' => 'Tatook M&E', 'start_url' => './', 'scope' => './', 'display' => 'standalone'])
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('any', 'maskable');

    foreach ($manifest['icons'] as $icon) {
        [$width, $height] = getimagesize(public_path('mel/'.$icon['src']));
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

it('keeps no data in the offline page or the worker', function () {
    $offline = (string) file_get_contents(public_path('mel/offline.html'));
    $worker = (string) file_get_contents(public_path('mel/sw.js'));

    expect($offline)->not->toContain('<script')
        ->and($worker)->not->toContain('localStorage')
        ->and($worker)->not->toContain('indexedDB')
        ->and($worker)->toContain("request.mode === 'navigate'");
});

it('links the manifest from every page', function () {
    $this->actingAsMember('acme', ['mel.programmes.view']);

    $this->get($this->workspaceUrl('acme'))->assertOk()
        ->assertSee('<link rel="manifest" href="/mel/manifest.webmanifest">', false);
});
