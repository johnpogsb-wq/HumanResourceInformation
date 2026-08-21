<?php

/*
|--------------------------------------------------------------------------
| AI document scanner
|--------------------------------------------------------------------------
| Reads an uploaded 201-file document and proposes the fields HR would
| otherwise type by hand. Config-driven like the rest of the system, so a new
| document type is a config edit rather than a code change.
|
| The scanner never writes to the database — it fills a form the human
| confirms. See App\Services\DocumentScanner.
*/

return [

    /*
    | Without an API key the whole feature stays dark: the Scan button is not
    | rendered and the endpoint 404s. Uploading by hand keeps working exactly
    | as before, so a missing key degrades the feature rather than the screen.
    */
    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('SCANNER_MODEL', 'claude-opus-5'),

    /*
    | Images only. A PDF or DOCX upload skips the scanner rather than failing
    | — the document still uploads, HR just types the fields.
    */
    'accepts' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],

    /*
    | 5 MB of raw image is roughly 6.7 MB base64-encoded, well inside the
    | request limit. Bigger scans are refused before they cost a request.
    */
    'max_bytes' => 5 * 1024 * 1024,

    /*
    | What each type looks like, in the model's own terms. Keys must match
    | EmployeeDocument::TYPES exactly — the scanner's answer is validated
    | against that list before it reaches the form, so a hallucinated type
    | is rejected rather than shown.
    */
    'document_types' => [
        'drivers_license' => "LTO driver's licence (professional or non-professional). Has an Expiration Date and a License No.",
        'government_id' => 'A government-issued ID: PhilSys National ID (PhilID), UMID, passport, postal ID, voter ID.',
        'clearance' => 'NBI clearance, police clearance, or barangay clearance. NBI clearances carry a "VALID UNTIL" date.',
        'medical' => 'Medical certificate, fit-to-work certificate, or pre-employment medical result.',
        'contract' => 'Employment contract, job offer, or appointment letter.',
        'certificate' => 'Training certificate, TESDA certificate, diploma, or seminar certificate.',
        'resume' => 'Résumé, CV, or biodata.',
        'other' => 'A document that does not clearly fit any category above.',
    ],
];
