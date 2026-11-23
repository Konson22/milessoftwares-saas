<?php

use App\Procurement\Models\Document;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Multi-tenancy: nothing of one organization is reachable from another,
 * whatever id is put in the URL. Answers are 404, so ids reveal nothing.
 */

beforeEach(function () {
    Storage::fake('local');

    // Globex buys something, with a supplier, an order and a document.
    $globex = team('globex');
    as_($globex['requester'], 'procurement_requester', 'globex');
    $this->theirRequisition = requisition(slug: 'globex');
    as_($globex['approver'], 'procurement_department_approver', 'globex');
    decide("requisitions/{$this->theirRequisition->id}/decide", slug: 'globex');
    as_($globex['officer'], 'procurement_officer', 'globex');
    $this->theirSupplier = supplier('Globex Vendor', 'globex');
    $this->post(p('orders', 'globex'), ['requisition_id' => $this->theirRequisition->id, 'supplier_id' => $this->theirSupplier->id])->assertSessionHasNoErrors();
    $this->theirOrder = order('globex');
    $this->post(p("documents/order/{$this->theirOrder->id}", 'globex'), ['file' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
    $this->theirDocument = inTenant(fn () => Document::query()->sole(), 'globex');

    // Acme's manager, an owner-level view of Acme only.
    $this->manager = actingAsRole('procurement_manager');
});

it('answers 404 for another organization\'s records', function () {
    $this->get(p("requisitions/{$this->theirRequisition->id}"))->assertNotFound();
    $this->get(p("suppliers/{$this->theirSupplier->id}"))->assertNotFound();
    $this->get(p("orders/{$this->theirOrder->id}"))->assertNotFound();
    $this->get(p("orders/{$this->theirOrder->id}/print"))->assertNotFound();
    $this->get(p("documents/{$this->theirDocument->id}"))->assertNotFound();
});

it('refuses to act on another organization\'s records', function () {
    $this->post(p("orders/{$this->theirOrder->id}/submit"))->assertNotFound();
    $this->post(p("orders/{$this->theirOrder->id}/cancel"), ['reason' => 'x'])->assertNotFound();
    $this->post(p("requisitions/{$this->theirRequisition->id}/decide"), ['decision' => 'approve'])->assertNotFound();
    $this->post(p("suppliers/{$this->theirSupplier->id}/status"), ['status' => 'suspended', 'reason' => 'x'])->assertNotFound();
    $this->post(p("documents/order/{$this->theirOrder->id}"), ['file' => UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')])->assertNotFound();

    expect(inTenant(fn () => PurchaseOrder::query()->sole()->status, 'globex'))->toBe('draft')
        ->and(inTenant(fn () => Supplier::query()->sole()->status, 'globex'))->toBe('active');
});

it('refuses another organization\'s supplier or requisition inside its own forms', function () {
    $this->post(p('orders'), ['requisition_id' => $this->theirRequisition->id, 'supplier_id' => $this->theirSupplier->id])->assertNotFound();

    $requisition = requisition();
    $this->actingAsMember('acme', [...rolePermissions('procurement_manager')], owner: true);
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasNoErrors();
    $this->post(p('orders'), ['requisition_id' => $requisition->id, 'supplier_id' => $this->theirSupplier->id])->assertSessionHasErrors('supplier_id');
});

it('lists only the organization\'s own records', function () {
    supplier('Acme Vendor');
    $this->get(p('suppliers'))->assertInertia(fn ($page) => $page->has('suppliers', 1)->where('suppliers.0.name', 'Acme Vendor'));
    $this->get(p('orders'))->assertInertia(fn ($page) => $page->has('orders', 0));
});

it('serves documents only to members who can open the record', function () {
    $requester = actingAsRole('procurement_requester', slug: 'globex', name: 'Globex requester');
    $this->get(p("documents/{$this->theirDocument->id}", 'globex'))->assertNotFound();

    $officer = actingAsRole('procurement_officer', slug: 'globex', name: 'Globex officer');
    $this->get(p("documents/{$this->theirDocument->id}", 'globex'))->assertOk()->assertDownload('quote.pdf');
});
