<?php
declare(strict_types=1);

if (!function_exists('event_tab_link_html')) {
    function event_tab_link_html(string $href, string $label, bool $active): string
    {
        $activeClass = 'border-orange-500 text-orange-600 font-bold';
        $inactiveClass = 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 font-semibold';
        $classes = $active ? $activeClass : $inactiveClass;

        return '<a href="' . htmlspecialchars($href) . '" class="' . $classes . ' whitespace-nowrap border-b-2 py-3 px-1 text-sm transition">'
            . htmlspecialchars($label)
            . '</a>';
    }
}

if (!function_exists('render_event_tabs')) {
    /**
     * Render consistent event navigation tabs across event pages.
     *
     * Options:
     * - event_id (string, required)
     * - current_tab (details|participants|absence_reasons|feedback|questions|qr)
     * - role (admin|teacher|student)
     * - uses_sessions (bool)
     * - event_status (string)
     * - participant_day (optional string)
     */
    function render_event_tabs(array $options): void
    {
        $eventId = trim((string) ($options['event_id'] ?? ''));
        if ($eventId === '') {
            return;
        }

        $currentTab = strtolower(trim((string) ($options['current_tab'] ?? 'details')));
        $role = strtolower(trim((string) ($options['role'] ?? 'admin')));
        $usesSessions = (bool) ($options['uses_sessions'] ?? false);
        $status = strtolower(trim((string) ($options['event_status'] ?? '')));
        $isFinished = $status === 'finished';

        $participantDay = trim((string) ($options['participant_day'] ?? ''));
        $returnTo = trim((string) ($options['return_to'] ?? ''));
        $returnQuery = $returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : '';

        $eventQuery = 'event_id=' . rawurlencode($eventId);
        $participantsHref = '/participants.php?' . $eventQuery . '&participant_tab=participants' . $returnQuery;
        if ($participantDay !== '' && strtolower($participantDay) !== 'all') {
            $participantsHref .= '&day=' . rawurlencode($participantDay);
        }

        $absenceHref = '/participants.php?' . $eventQuery . '&participant_tab=absence_reasons' . $returnQuery;
        $feedbackHref = '/evaluation_admin.php?' . $eventQuery . '&tab=feedback';
        $questionsHref = '/evaluation_admin.php?' . $eventQuery . '&tab=questions';
        $qrHref = '/event_teachers.php?' . $eventQuery;
        $detailsHref = '/event_view.php?id=' . rawurlencode($eventId);

        echo '<div class="border-b border-zinc-200 mb-6 pt-2">';
        echo '<nav class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Tabs">';
        echo event_tab_link_html($detailsHref, 'Event Details', $currentTab === 'details');
        echo event_tab_link_html($participantsHref, 'Event Participants', $currentTab === 'participants');

        if ($role === 'admin' && $isFinished) {
            echo event_tab_link_html($absenceHref, 'Absence Reasons', $currentTab === 'absence_reasons');
        }

        if ($isFinished) {
            echo event_tab_link_html($feedbackHref, 'Event Feedback', $currentTab === 'feedback');
        }

        if (!$isFinished) {
            echo event_tab_link_html($questionsHref, 'Evaluation Questions', $currentTab === 'questions');
            if ($role === 'admin') {
                echo event_tab_link_html($qrHref, 'QR Scanner Access', $currentTab === 'qr');
            }
        }

        echo '</nav>';
        echo '</div>';
    }
}
