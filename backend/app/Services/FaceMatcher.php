<?php

namespace App\Services;

use App\Models\FaceEnrollment;

/**
 * Compares a face template from the camera against the enrolled ones.
 *
 * The templates are face-api's 128-number descriptors. The comparison is run
 * here rather than in the browser so the enrolled template never leaves the
 * server: a page that held it could be made to report a match it never saw.
 */
class FaceMatcher
{
    public const DESCRIPTOR_LENGTH = 128;

    /** The closest distance between the template and any enrolled sample. */
    public function distance(FaceEnrollment $enrollment, array $descriptor): float
    {
        return collect($enrollment->descriptors)
            ->map(fn (array $sample) => $this->euclidean($sample, $descriptor))
            ->min() ?? INF;
    }

    public function matches(FaceEnrollment $enrollment, array $descriptor): bool
    {
        return $this->distance($enrollment, $descriptor) <= (float) config('timeclock.match_threshold');
    }

    /**
     * Whose face this is, among everybody enrolled — the kiosk's question.
     *
     * Two people both inside the threshold and close to each other is not a
     * match: taking the nearer would file a punch under a coin toss, and the
     * error would be silent afterwards — the same refusal the batch filer
     * makes for two people fitting one name. `ambiguous` says which it was so
     * the screen can ask for a clearer look rather than "not recognised".
     *
     * @return array{enrollment: ?FaceEnrollment, distance: float, ambiguous: bool}
     */
    public function identify(array $descriptor): array
    {
        $threshold = (float) config('timeclock.match_threshold');

        $ranked = FaceEnrollment::query()
            ->with('employee')
            ->whereHas('employee', fn ($query) => $query->where('status', '!=', 'inactive'))
            ->get()
            ->map(fn (FaceEnrollment $enrollment) => [
                'enrollment' => $enrollment,
                'distance' => $this->distance($enrollment, $descriptor),
            ])
            ->sortBy('distance')
            ->values();

        $best = $ranked->first();

        if (! $best || $best['distance'] > $threshold) {
            return ['enrollment' => null, 'distance' => $best['distance'] ?? INF, 'ambiguous' => false];
        }

        $runnerUp = $ranked->get(1);

        if ($runnerUp && (float) config('timeclock.identify_margin') > $runnerUp['distance'] - $best['distance']) {
            return ['enrollment' => null, 'distance' => $best['distance'], 'ambiguous' => true];
        }

        return ['enrollment' => $best['enrollment'], 'distance' => $best['distance'], 'ambiguous' => false];
    }

    /** A real template: 128 finite numbers in the range face-api produces. */
    public function isValidDescriptor(mixed $descriptor): bool
    {
        if (! is_array($descriptor) || count($descriptor) !== self::DESCRIPTOR_LENGTH || ! array_is_list($descriptor)) {
            return false;
        }

        foreach ($descriptor as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > 1.5) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, float> */
    public function normalise(array $descriptor): array
    {
        return array_map(fn ($value) => round((float) $value, 6), $descriptor);
    }

    private function euclidean(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $index => $value) {
            $difference = (float) $value - (float) ($b[$index] ?? 0);
            $sum += $difference * $difference;
        }

        return sqrt($sum);
    }
}
