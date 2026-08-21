<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            // Authorized routes, not direct storage URLs — the file sits on a
            // private disk and is streamed only after a policy check.
            'url' => route('hr.employees.documents.download', [
                'employee' => $this->employee_id,
                'document' => $this->id,
            ]),
            'preview_url' => route('hr.employees.documents.preview', [
                'employee' => $this->employee_id,
                'document' => $this->id,
            ]),
            // What the viewer should render it as. Decided here from the
            // stored mime type rather than by sniffing the filename in the
            // browser, so an oddly-named upload still renders correctly —
            // and an unknown type falls back to a download rather than an
            // empty frame.
            'preview_as' => $this->previewAs(),
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'is_expired' => $this->isExpired(),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * `image`, `pdf`, `text`, or null when the browser cannot show it — a
     * .docx has no viewer, and offering a preview that renders a blank frame
     * is worse than offering only the download.
     */
    private function previewAs(): ?string
    {
        $mime = (string) $this->mime_type;

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'text/') => 'text',
            default => null,
        };
    }
}
