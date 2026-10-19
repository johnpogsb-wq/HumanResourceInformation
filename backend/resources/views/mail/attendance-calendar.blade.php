{{--
    One employee's month of time records, read in a mail client.

    Tables and inline styles only: Gmail drops most of a <style> block on
    mobile and knows nothing of grid or flex, so the calendar is a seven-column
    table like every mail client agrees on. The colours are the brand table's
    and the status colours of the calendar screen, written as values because a
    mail client cannot read the app's CSS tokens — the same reason the PDF
    report view is plain inline styles.
--}}
@php
    $labels = [
        'present' => 'Present',
        'late' => 'Late',
        'undertime' => 'Undertime',
        'absent' => 'Absent',
        'incomplete' => 'No time-out',
        'rest_day' => 'Rest day',
        'holiday' => 'Holiday',
        'on_leave' => 'On leave',
    ];
    $colours = [
        'present' => '#15803D',
        'late' => '#B45309',
        'undertime' => '#B45309',
        'absent' => '#B91C1C',
        'incomplete' => '#B91C1C',
        'rest_day' => '#64748B',
        'holiday' => '#007DCC',
        'on_leave' => '#007DCC',
    ];
    $summary = $month['summary'];
    $position = $employee->position?->title;
    $placement = $employee->client?->name ?? $employee->department?->name;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance — {{ $month['label'] }}</title>
</head>
<body style="margin:0;padding:0;background:#F2F7FC;font-family:Arial,Helvetica,sans-serif;color:#0A1B2E;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F7FC;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#FFFFFF;border:1px solid #D6E4F0;border-radius:8px;">
                <tr>
                    <td style="background:#0A1B2E;color:#FFFFFF;padding:18px 20px;border-radius:8px 8px 0 0;">
                        <div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#9CC9EC;">{{ $company }}</div>
                        <div style="font-size:20px;font-weight:bold;margin-top:4px;">Attendance calendar · {{ $month['label'] }}</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 20px 6px;">
                        <div style="font-size:16px;font-weight:bold;">{{ $employee->full_name }}</div>
                        <div style="font-size:13px;color:#5B6B7C;margin-top:2px;">
                            {{ $employee->employee_number }}@if ($position) · {{ $position }}@endif @if ($placement) · {{ $placement }}@endif
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 20px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                @foreach ([
                                    ['Days worked', $summary['worked'], '#15803D'],
                                    ['Late', $summary['late'], $summary['late'] > 0 ? '#B45309' : '#64748B'],
                                    ['Absences', $summary['absent'], $summary['absent'] > 0 ? '#B91C1C' : '#64748B'],
                                    ['Hours', $summary['hours'], '#007DCC'],
                                ] as [$label, $value, $colour])
                                    <td width="25%" style="padding:4px;">
                                        <div style="border:1px solid #D6E4F0;border-radius:6px;padding:10px 8px;text-align:center;background:#EDF5FB;">
                                            <div style="font-size:20px;font-weight:bold;color:{{ $colour }};">{{ $value }}</div>
                                            <div style="font-size:11px;color:#5B6B7C;margin-top:2px;">{{ $label }}</div>
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:6px 20px 4px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;table-layout:fixed;">
                            <tr>
                                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                                    <th style="font-size:11px;font-weight:normal;color:#5B6B7C;padding:4px 0;">{{ $weekday }}</th>
                                @endforeach
                            </tr>
                            @foreach ($weeks as $week)
                                <tr>
                                    @foreach ($week as $day)
                                        @if ($day === null)
                                            <td style="border:1px solid #EDF2F7;background:#FAFCFE;height:64px;"></td>
                                        @else
                                            @php
                                                $status = $day['status'];
                                                $label = $status ? $labels[$status] : ($day['future'] ? '' : ($day['holiday'] ? 'Holiday' : ($day['rest_day'] ? 'Rest day' : 'No record')));
                                                $colour = $status ? $colours[$status] : '#94A3B8';
                                            @endphp
                                            <td valign="top" style="border:1px solid #E2EAF2;height:64px;padding:4px;font-size:11px;line-height:1.35;">
                                                <div style="font-weight:bold;font-size:12px;">{{ $day['day'] }}</div>
                                                @if ($day['time_in'] || $day['time_out'])
                                                    <div>{{ $day['time_in'] ?? '—' }}</div>
                                                    <div>{{ $day['time_out'] ?? '—' }}@if ($day['next_day_out'])<sup>+1</sup>@endif</div>
                                                @endif
                                                @if ($label !== '')
                                                    <div style="color:{{ $colour }};font-weight:bold;">{{ $label }}</div>
                                                @endif
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </table>
                        <div style="font-size:11px;color:#5B6B7C;padding-top:6px;">Each day shows time in, time out, and how the day was counted. <sup>+1</sup> means the time-out was the next morning.</div>
                    </td>
                </tr>

                @if (count($exceptions) > 0)
                    <tr>
                        <td style="padding:14px 20px 4px;">
                            <div style="font-size:14px;font-weight:bold;margin-bottom:6px;">Days to check</div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:12px;">
                                <tr style="background:#EDF5FB;">
                                    <th align="left" style="padding:6px;border:1px solid #D6E4F0;">Date</th>
                                    <th align="left" style="padding:6px;border:1px solid #D6E4F0;">Shift</th>
                                    <th align="left" style="padding:6px;border:1px solid #D6E4F0;">In / Out</th>
                                    <th align="left" style="padding:6px;border:1px solid #D6E4F0;">Status</th>
                                </tr>
                                @foreach ($exceptions as $day)
                                    <tr>
                                        <td style="padding:6px;border:1px solid #E2EAF2;">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D, M j') }}</td>
                                        <td style="padding:6px;border:1px solid #E2EAF2;">{{ $day['shift'] ? $day['shift']['start'].'–'.$day['shift']['end'] : '—' }}</td>
                                        <td style="padding:6px;border:1px solid #E2EAF2;">{{ $day['time_in'] ?? '—' }} / {{ $day['time_out'] ?? '—' }}</td>
                                        <td style="padding:6px;border:1px solid #E2EAF2;color:{{ $colours[$day['status']] }};font-weight:bold;">
                                            {{ $labels[$day['status']] }}
                                            @if ($day['late_minutes'] > 0) · {{ $day['late_minutes'] }} min late @endif
                                            @if ($day['undertime_minutes'] > 0) · {{ $day['undertime_minutes'] }} min short @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:18px 20px 22px;">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="background:#007DCC;border-radius:6px;">
                                    <a href="{{ $url }}" style="display:inline-block;padding:10px 18px;color:#FFFFFF;font-size:14px;font-weight:bold;text-decoration:none;">Open my attendance calendar</a>
                                </td>
                            </tr>
                        </table>
                        <div style="font-size:12px;color:#5B6B7C;margin-top:12px;line-height:1.5;">
                            If a day here is wrong, sign in and file a Time Correction for it — HR does not change a time record on request by email.
                        </div>
                    </td>
                </tr>
            </table>
            <div style="font-size:11px;color:#8A99A8;margin-top:12px;">Sent by {{ $company }}. This message contains your personal attendance record.</div>
        </td>
    </tr>
</table>
</body>
</html>
