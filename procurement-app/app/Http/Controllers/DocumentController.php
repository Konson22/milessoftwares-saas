<?php

namespace App\Http\Controllers;

use App\Procurement\Documents;
use App\Procurement\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supporting documents: added and downloaded only by someone who can open
 * the record they belong to (404 otherwise).
 */
class DocumentController extends Controller
{
    public function store(Request $request, string $type, string $id): RedirectResponse
    {
        abort_unless(in_array($type, Documents::SUBJECTS, true), 404);
        [$subject, $mayAdd] = Documents::subject($type, $id);
        abort_unless($mayAdd, 403);

        $data = $request->validate(['file' => ['required', 'file', 'max:'.Documents::MAX_KILOBYTES, 'mimes:'.Documents::MIMES]]);
        Documents::attach($type, $subject, $data['file'], $this->user());

        return back()->with('success', 'Document added.');
    }

    public function download(Document $document): StreamedResponse
    {
        Documents::subject($document->getAttribute('subject_type'), $document->getAttribute('subject_id'));

        return Storage::disk('local')->download($document->getAttribute('path'), $document->getAttribute('name'));
    }
}
