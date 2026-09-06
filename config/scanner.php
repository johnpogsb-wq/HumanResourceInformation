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
    | Which model reads the document.
    |
    | `ollama` runs it on this machine and is the default, for two reasons.
    | The obvious one is cost — there is no bill. The one that actually
    | decides it: a 201-file scan is a photograph of somebody's PhilSys ID,
    | NBI clearance, or licence. Sending that to a third-party API is a
    | cross-border transfer of personal data under RA 10173 (the Data Privacy
    | Act), which needs consent and a processing agreement the school project
    | has neither of. Locally, the image never leaves the host.
    |
    | `anthropic` remains supported for a deployment that has cleared that
    | and wants the accuracy — the two drivers return the same shape, and
    | everything downstream of read() is driver-agnostic.
    */
    'driver' => env('SCANNER_DRIVER', 'ollama'),

    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://127.0.0.1:11434'),

        /*
        | glm-ocr is 0.9B parameters and about 2.2 GB — small enough to sit in
        | a 4 GB card's VRAM, and built for documents rather than for chat.
        | A general vision model of this size reads a licence noticeably worse.
        */
        'model' => env('OLLAMA_SCANNER_MODEL', 'glm-ocr'),

        /*
        | A cold model has to be loaded into VRAM before it can answer, which
        | is most of the first request. Later scans are far quicker; the
        | timeout has to cover the first one or the feature looks broken
        | exactly once per reboot.
        */
        'timeout' => (int) env('OLLAMA_TIMEOUT', 180),
    ],

    /*
    | The deployment driver.
    |
    | Ollama is the better answer wherever it can run — the image never leaves
    | the host — but it has to be installed on whatever serves the app, and a
    | small VPS cannot hold even a 2.2 GB vision model. Gemini runs from
    | anywhere and has a free tier, which is what makes a deployed demo
    | possible at all.
    |
    | The cost is the one Ollama avoids: the scan leaves the country. A
    | deployment on this driver is processing PhilSys IDs and NBI clearances
    | through a third party, which under RA 10173 needs consent and
    | disclosure — a decision to make deliberately, not by editing an env file.
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),

        /*
        | The API *base*. DocumentScanner::geminiEndpoint() appends
        | `/models/{model}:generateContent`, because Gemini names the model in
        | the URL rather than in the request body.
        */
        'endpoint' => env(
            'GEMINI_ENDPOINT',
            'https://generativelanguage.googleapis.com/v1beta',
        ),

        'model' => env('GEMINI_MODEL', 'gemini-3.7-flash'),

        // Nothing to load into VRAM here, so the long cold-start allowance
        // Ollama needs does not apply — but a large scan still has to upload.
        /*
        | How many times to wait out a busy model.
        |
        | The free tier shares one pool, and a heavy request — an image plus a
        | schema — comes back `UNAVAILABLE` under load often enough that a
        | single attempt is a coin toss. Only 429 and 5xx are retried; a wrong
        | key fails on the first try, as it should.
        */
        'retries' => (int) env('GEMINI_RETRIES', 3),
        'retry_delay_ms' => (int) env('GEMINI_RETRY_DELAY_MS', 1500),

        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
    ],

    /*
    | Only read when driver = anthropic. Without a key that driver stays dark:
    | the Scan button is not rendered and the endpoint 404s. Uploading by hand
    | keeps working exactly as before either way.
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
    /*
    | The default title for each type, used in preference to the one the model
    | writes.
    |
    | The type is validated against EmployeeDocument::TYPES before it is
    | trusted; the free-text title is validated against nothing, so between
    | the two the derived label is the sounder source. It also holds the
    | naming steady across a 201 file — twenty licences all titled "Driver's
    | Licence" sort and read better than twenty variations. HR can still type
    | over it, which is where any real specificity belongs.
    */
    /*
    | What a document's own printed title implies about its type, checked in
    | PHP against the free text the model transcribed.
    |
    | This exists because the small model is measurably better at *reading* a
    | heading than at picking the matching key out of a list. On an NBI
    | clearance it wrote title "NBI Clearance" — correct — while answering
    | type "drivers_license". The heading is evidence from the document; the
    | enum is the model's judgement about it, and the two are not equally
    | trustworthy.
    |
    | Matched in order, first hit wins, so put the specific before the
    | general — "driver's licence" has to be tested before a bare "licence".
    */
    /*
    | Matched against the whole heading, in this order, first hit winning.
    |
    | **The issuing authority is the strongest thing printed on a Philippine
    | document, and it is printed in full.** The list began as abbreviations —
    | "nbi", "lto" — and missed a real NBI clearance whose letterhead reads
    | "REPUBLIC OF THE PHILIPPINES / Department of Justice / National Bureau of
    | Investigation". The word "NBI" appears nowhere on it. Agencies write
    | themselves out; the shorthand is what people say, not what they print.
    |
    | Order still matters for the overlaps: "certificate" would swallow a PSA
    | birth certificate, so `psa` is tested first.
    */
    'title_keywords' => [
        'clearance' => [
            'nbi', 'clearance', 'police', 'barangay',
            // Spelled out, which is how the letterhead actually reads. "bureau
            // of investigation" rather than the full name so a dropped or
            // misread "National" does not lose the match — OCR mangles the
            // long line often enough to matter.
            'bureau of investigation', 'department of justice',
            'philippine national police', 'punong barangay',
        ],
        'drivers_license' => [
            'driver', 'licence', 'license', 'lto',
            'land transportation office', 'land transportation',
        ],
        // "patient" is a genuine signal, not a fitting to one sample: nothing
        // else in a 201 file addresses the holder as a patient.
        'medical' => [
            'medical', 'fit to work', 'fit-to-work', 'health', 'patient',
            'department of health', 'diagnostic', 'laboratory',
        ],
        'contract' => ['contract', 'appointment', 'job offer'],
        /*
         * Before `certificate` on purpose — first hit wins, and "PSA Birth
         * Certificate" contains the word "certificate". Reversed, every PSA
         * document would be filed as a training certificate.
         */
        'psa' => [
            'psa', 'philippine statistics authority', 'birth certificate',
            'certificate of live birth', 'marriage certificate',
            'certificate of marriage', 'cenomar', 'no marriage record',
            'civil registry', 'nso',
            'philippine statistics authority', 'national statistics office',
            'civil registrar', 'office of the civil registrar',
        ],
        'certificate' => ['certificate', 'tesda', 'diploma', 'training'],
        'resume' => ['resume', 'résumé', 'curriculum vitae', 'biodata'],
        // Filipino field labels are the tell: PhilID and the passport caption
        // their fields in Filipino, and no other 201-file document does.
        'government_id' => [
            'philsys', 'philid', 'umid', 'passport', 'postal id', 'voter',
            // The PhilID prints its captions in Filipino, and no other 201-file
            // document does — which is what makes these safe as a heading test.
            'apelyido', 'pangalan', 'republika',
            // Issuing bodies, as they appear across the top of the card. A
            // heading is the one place these words are unambiguous: "Social
            // Security System" printed as a title is an SSS card, while the
            // same words inside a field are just a label.
            'philippine identification', 'pambansang', 'national id',
            'social security system', 'unified multi-purpose',
            'professional regulation', 'integrated bar',
            'philippine health insurance', 'philhealth',
            'home development mutual fund', 'pag-ibig',
            'bureau of internal revenue', 'department of foreign affairs',
            'commission on elections', 'philippine postal',
        ],
    ],

    /*
    | What an ID number for each type should look like, once punctuation is
    | stripped and it is lower-cased.
    |
    | This is the check that reads the *document* rather than the name on it:
    | an LTO licence number is a letter and ten digits, so "ABC123" is either a
    | misread or not a licence at all. Several patterns per type because one
    | type can cover several cards — `government_id` is PhilSys, SSS,
    | PhilHealth, Pag-IBIG and the TIN at once.
    |
    | **Reported, never blocking.** Agencies change their formats, an employee
    | may hold an older card issued under a previous one, and OCR drops a digit
    | often enough that refusing on shape alone would reject real documents.
    | It is a reason to look, not a reason to stop — the number *matching the
    | 201 file* is what carries weight, and that has its own check.
    */
    'number_formats' => [
        // N01-23-456789 -> n0123456789
        'drivers_license' => ['/^[a-z]\d{10}$/'],

        'government_id' => [
            '/^\d{16}$/',   // PhilSys PSN
            '/^\d{10}$/',   // SSS
            '/^\d{12}$/',   // PhilHealth, Pag-IBIG
            '/^\d{9}$/',    // TIN, without the branch code
            '/^\d{12}$/',   // TIN with it
            '/^[a-z]{1,2}\d{6,9}$/', // passport
        ],
    ],

    /*
    | How long each kind of document stays valid, in months, as a range.
    |
    | The gap between a document's issue date and its expiry is evidence about
    | what the document *is*, and it is evidence the model cannot fake: it
    | comes from two dates it transcribed separately. An LTO licence runs five
    | or ten years and nothing else in a 201 file does, so a sixty-month gap
    | identifies a licence on its own — which matters, because a licence and a
    | clearance are the two types this model confuses most.
    |
    | The ranges deliberately overlap where reality overlaps: an NBI clearance
    | is a year, a police or barangay clearance six months, a medical six to
    | twelve. Between those three the gap decides nothing, and the code only
    | uses this signal when exactly one type fits. A range that had to be made
    | artificially narrow to force an answer would be inventing certainty.
    |
    | Wide bounds on purpose. A document issued a fortnight before its stated
    | start, or renewed early, must not stop being recognisable.
    */
    'validity_months' => [
        // Five years, or ten for a clean record. Nothing else runs this long.
        'drivers_license' => [48, 132],

        // NBI is a year; police and barangay are commonly six months.
        'clearance' => [5, 15],

        // Pre-employment and fit-to-work results, six months to a year.
        'medical' => [3, 14],
    ],

    /*
    | The number formats that identify a type on their own.
    |
    | A deliberately shorter list than `number_formats`, and the split is the
    | point: a pattern can be good enough to *check* a number once the type is
    | known and nowhere near good enough to *decide* the type.
    |
    | The passport shape `[a-z]{1,2}\d{6,9}` is the case that made this
    | necessary. It is a fine check on a document already filed as a government
    | ID, and as a type rule it swallowed a medical certificate numbered
    | "MC-2026-4471" — two letters and eight digits, which is exactly what it
    | asks for. Bare digit counts fail the same way: a certificate number can
    | be ten digits and mean nothing of the sort.
    |
    | What is left is what is genuinely unmistakable in a 201 file: an LTO
    | licence is one letter and exactly ten digits, and a PhilSys PSN is
    | exactly sixteen. Nothing else here is either.
    */
    'type_defining_formats' => [
        'drivers_license' => ['/^[a-z]\d{10}$/'],
        'government_id' => ['/^\d{16}$/'],
    ],
    /*
    | What a document of each type cannot carry.
    |
    | Negative evidence, and it is the signal that catches the model's guess
    | being wrong when every positive signal has already declined. A résumé
    | does not have an ID number and does not expire; a PSA civil registry
    | document does not expire either. So a reading that says "resume" while
    | reporting a printed number has contradicted itself, and the honest answer
    | is that the type is unknown — not some other guess.
    |
    | Deliberately only the two types where the claim is absolute. An
    | employment contract *does* carry an end date, and a training certificate
    | may or may not carry a number, so neither can be ruled out this way. A
    | rule that is right most of the time is not usable here: it would discard
    | correct readings to catch incorrect ones.
    */
    'type_cannot_have' => [
        'resume' => ['document_number', 'expires_at'],
        'psa' => ['expires_at'],
    ],

    /*
    | Types where the name on the paper is *expected* to be somebody else.
    |
    | The name check refuses an upload whose document names a different person,
    | because filing under the wrong employee is an error nobody afterwards
    | goes looking for. A PSA birth certificate breaks that rule honestly: the
    | one filed in a 201 file is usually the employee's **child's**, kept for
    | BIR and PhilHealth dependant claims, and it names the child.
    |
    | So for these the check still runs and still reports — HR is told whose
    | name is on the paper — but it does not refuse. The alternative was the
    | PDF escape hatch, which works and which nobody would ever discover.
    */
    'names_may_differ' => ['psa'],

    /*
    |--------------------------------------------------------------------------
    | Filing without a person
    |--------------------------------------------------------------------------
    |
    | The batch filer may file a document nobody looked at — but only when
    | every check it has agrees, and every gate below is a real failure it has
    | already seen rather than a number picked to make the demo work.
    |
    | The rule the whole scanner is built on does not change: a wrong reading
    | must never become a fact quietly. What changes is where the person is
    | spent. Today they retype forty documents to catch the two that are
    | wrong; here the system files the thirty-eight it can defend and hands
    | back the two, which is the same safety with the effort put where the
    | risk is.
    |
    | Every one of these is *held*, not refused: a held document goes to the
    | review table the batch filer has always had, and is filed by hand
    | exactly as before. Nothing is ever discarded for failing a gate.
    |
    | Switch `enabled` off and the batch filer behaves exactly as it did —
    | propose everything, file nothing until somebody confirms.
    */
    'autofile' => [
        'enabled' => (bool) env('SCANNER_AUTOFILE', true),

        /*
         * How the owner was found. `number` is a value already on this
         * employee's 201 file — the only evidence in the whole reading with a
         * person behind it rather than a model. `name` is one exact match
         * against the scoped roster and no other: the filer already refuses
         * two, because taking the first would file the document under a coin
         * toss.
         *
         * Dropping `name` here is the one-line way to make this stricter, and
         * it is the first thing to try if a real batch ever files something
         * wrong.
         */
        'match_strengths' => ['number', 'name'],

        /*
         * The type must be *certain* — settled by a number already on the 201
         * file, or by the document's own printed heading. The three weaker
         * sources are exactly the ones measured wrong: a number's shape is
         * shared between cards (a medical certificate numbered "MC-2026-4471"
         * fits the passport pattern), a validity period overlaps between
         * types, and the model's own key was wrong 4/4 on an NBI clearance
         * whose heading it had transcribed correctly every time.
         */
        'require_certain_type' => true,

        /*
         * A document whose type expires must have brought a date with it.
         * `CredentialExpiryScanner` reads `expires_at`, so a licence filed
         * with a null date is a licence that never appears in a renewal queue
         * — invisible rather than wrong, which is worse.
         */
        'require_expiry_for_expiring_types' => true,

        /*
         * Already lapsed is held. Filing an expired document is legitimate and
         * happens often — for the record, or mid-renewal — which is why the
         * upload form reports it rather than refusing it. But it is never the
         * thing to do silently: somebody should see that what just went into
         * the file cannot be used.
         */
        'hold_expired' => true,
    ],

    'labels' => [
        'drivers_license' => "Driver's Licence",
        'government_id' => 'Government ID',
        'clearance' => 'Clearance',
        'medical' => 'Medical Certificate',
        'contract' => 'Employment Contract',
        'psa' => 'PSA Certificate',
        'certificate' => 'Certificate',
        'resume' => 'Résumé',
        'other' => 'Document',
    ],

    'document_types' => [
        'drivers_license' => "LTO driver's licence (professional or non-professional). Has an Expiration Date and a License No.",
        'government_id' => 'A government-issued ID: PhilSys National ID (PhilID), UMID, passport, postal ID, voter ID.',
        'clearance' => 'NBI clearance, police clearance, or barangay clearance. NBI clearances carry a "VALID UNTIL" date.',
        'medical' => 'Medical certificate, fit-to-work certificate, or pre-employment medical result.',
        'contract' => 'Employment contract, job offer, or appointment letter.',
        'psa' => 'A PSA (formerly NSO) civil registry document: birth certificate, marriage certificate, or CENOMAR. Printed on security paper and headed "Philippine Statistics Authority".',
        'certificate' => 'Training certificate, TESDA certificate, diploma, or seminar certificate.',
        'resume' => 'Résumé, CV, or biodata.',
        'other' => 'A document that does not clearly fit any category above.',
    ],
];
