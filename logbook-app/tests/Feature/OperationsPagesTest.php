<?php

/*
 * Every operations page renders for every role, and managing actions are
 * refused to those without the permission (ADR-0026).
 */

beforeEach(function () {
    $this->team = team();
});

it('renders the operations pages for each role', function (string $who, string $role) {
    as_($this->team[$who], $role);
    foreach ([
        '' => 'home',
        'tasks' => 'tasks/index',
        'tasks?tab=recurring' => 'tasks/index',
        'exceptions' => 'exceptions/index',
        'schedules' => 'schedules/index',
        'notifications' => 'notifications/index',
        'resources' => 'resources/index',
    ] as $path => $component) {
        $this->get(p($path))->assertOk()->assertInertia(fn ($page) => $page->component($component)->has('unread'));
    }
    $this->getJson(p('notifications/recent'))->assertOk()->assertJsonStructure(['unread', 'latest']);
})->with([
    ['manager', 'logbook_manager'],
    ['writer', 'logbook_contributor'],
    ['reviewer', 'logbook_reviewer'],
    ['viewer', 'logbook_viewer'],
    ['auditor', 'logbook_auditor'],
]);

it('keeps managing work to those allowed', function () {
    as_($this->team['viewer'], 'logbook_viewer');
    $this->post(p('task-series'), [])->assertForbidden();
    $this->post(p('resources'), ['code' => 'X', 'name' => 'X', 'type' => 'X'])->assertForbidden();
    $this->post(p('exceptions'), ['title' => 'x', 'severity' => 'low'])->assertForbidden();
    $this->get(p('tasks?tab=all'))->assertInertia(fn ($page) => $page->where('tab', 'mine')->where('canManage', false));
});

it('gives the phone its due inspections, notifications and resources', function () {
    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    $this->getJson(p('api/v1/due'))->assertOk()->assertJsonStructure(['serverTime', 'occurrences']);
    $this->getJson(p('api/v1/notifications'))->assertOk()->assertJsonPath('unread', 0);
    $this->postJson(p('api/v1/notifications/read'), ['all' => true])->assertOk();
    $this->getJson(p('api/v1/kit'))->assertOk()->assertJsonStructure(['resources', 'can' => ['manageTasks']]);
});
