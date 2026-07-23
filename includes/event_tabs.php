<?php
declare(strict_types=1);

if (!function_exists('event_management_return_to')) {
    /**
     * Resolve a safe in-app back URL for event management pages.
     */
    function event_management_return_to(string $role, ?string $requested = null): string
    {
        $requested = trim((string) $requested);
        if ($requested !== ''
            && str_starts_with($requested, '/')
            && !str_starts_with($requested, '//')
            && !str_contains($requested, "\n")
            && !str_contains($requested, "\r")
        ) {
            return $requested;
        }

        $role = strtolower(trim($role));
        return $role === 'teacher' ? '/manage_events.php' : '/events.php';
    }
}

if (!function_exists('event_tab_link_html')) {
    function event_tab_link_html(string $href, string $label, bool $active, array $attrs = []): string
    {
        $activeClass = 'border-orange-500 text-orange-600 font-bold';
        $inactiveClass = 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 font-semibold';
        $classes = $active ? $activeClass : $inactiveClass;

        $extra = '';
        foreach ($attrs as $key => $value) {
            $extra .= ' ' . htmlspecialchars((string) $key) . '="' . htmlspecialchars((string) $value) . '"';
        }

        return '<a href="' . htmlspecialchars($href) . '" class="' . $classes . ' whitespace-nowrap border-b-2 py-3 px-1 text-sm transition"' . $extra . '>'
            . htmlspecialchars($label)
            . '</a>';
    }
}

if (!function_exists('render_event_back_button')) {
    function render_event_back_button(string $href, string $title = 'Back'): string
    {
        return '<a href="' . htmlspecialchars($href) . '"'
            . ' title="' . htmlspecialchars($title) . '"'
            . ' class="flex items-center justify-center w-8 h-8 rounded-full bg-white border border-zinc-200 hover:bg-zinc-50 text-zinc-600 transition shadow-sm">'
            . '<svg class="w-4 h-4 mr-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">'
            . '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>'
            . '</svg>'
            . '</a>';
    }
}

if (!function_exists('render_event_page_header')) {
    /**
     * Shared event-page header: back button + title (+ optional subtitle/status/actions).
     *
     * Options:
     * - back_href (string, required)
     * - title (string, required)
     * - subtitle (string, optional)
     * - status_html (string, optional pre-rendered badge)
     * - actions_html (string, optional)
     * - back_title (string, optional)
     */
    function render_event_page_header(array $options): void
    {
        $backHref = trim((string) ($options['back_href'] ?? ''));
        if ($backHref === '') {
            $backHref = '/events.php';
        }
        $title = (string) ($options['title'] ?? '');
        $subtitle = trim((string) ($options['subtitle'] ?? ''));
        $statusHtml = (string) ($options['status_html'] ?? '');
        $actionsHtml = (string) ($options['actions_html'] ?? '');
        $backTitle = trim((string) ($options['back_title'] ?? 'Back'));

        echo '<div class="mb-4">';
        echo '<div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">';
        echo '<div class="flex items-center gap-3 min-w-0">';
        echo render_event_back_button($backHref, $backTitle !== '' ? $backTitle : 'Back');

        if ($subtitle !== '') {
            echo '<div class="min-w-0">';
            echo '<h2 class="text-xl md:text-2xl font-bold text-zinc-900 leading-tight">'
                . htmlspecialchars($title)
                . '</h2>';
            echo '<p class="text-sm text-zinc-500 mt-1">' . htmlspecialchars($subtitle) . '</p>';
            echo '</div>';
        } else {
            echo '<h2 class="text-xl md:text-2xl font-bold text-zinc-900">'
                . htmlspecialchars($title)
                . '</h2>';
            echo $statusHtml;
        }

        echo '</div>';

        if ($actionsHtml !== '') {
            echo '<div class="flex flex-wrap items-center gap-2.5">' . $actionsHtml . '</div>';
        }

        echo '</div>';
        echo '</div>';
    }
}

if (!function_exists('render_event_tabs')) {
    /**
     * Render consistent event navigation tabs across event pages.
     *
     * Options:
     * - event_id (string, required)
     * - current_tab (details|participants|payments|document_review|absence_reasons|feedback|questions|qr)
     * - role (admin|teacher|student)
     * - uses_sessions (bool)
     * - event_status (string)
     * - participant_day (optional string)
     * - return_to (optional string)
     * - has_student_requirements (bool)
     * - is_event_creator (bool) — teachers who created the event; admins should pass true
     * - is_paid_event (bool)
     */
    function render_event_tabs(array $options): void
    {
        $eventId = trim((string) ($options['event_id'] ?? ''));
        if ($eventId === '') {
            return;
        }

        $currentTab = strtolower(trim((string) ($options['current_tab'] ?? 'details')));
        $role = strtolower(trim((string) ($options['role'] ?? 'admin')));
        $status = strtolower(trim((string) ($options['event_status'] ?? '')));
        $isFinished = $status === 'finished';

        $participantDay = trim((string) ($options['participant_day'] ?? ''));
        $returnTo = event_management_return_to($role, (string) ($options['return_to'] ?? ''));
        $returnQuery = '&return_to=' . rawurlencode($returnTo);

        $eventQuery = 'event_id=' . rawurlencode($eventId);
        $participantsHref = '/participants.php?' . $eventQuery . '&participant_tab=participants' . $returnQuery;
        if ($participantDay !== '' && strtolower($participantDay) !== 'all') {
            $participantsHref .= '&day=' . rawurlencode($participantDay);
        }

        $absenceHref = '/participants.php?' . $eventQuery . '&participant_tab=absence_reasons' . $returnQuery;
        $feedbackHref = '/evaluation_admin.php?' . $eventQuery . '&tab=feedback' . $returnQuery;
        $questionsHref = '/evaluation_admin.php?' . $eventQuery . '&tab=questions' . $returnQuery;
        $qrHref = '/event_teachers.php?' . $eventQuery . $returnQuery;
        $detailsHref = '/event_view.php?id=' . rawurlencode($eventId) . $returnQuery;
        $documentReviewHref = '/event_document_review.php?event_id=' . rawurlencode($eventId) . $returnQuery;
        $paymentsHref = '/event_payments.php?event_id=' . rawurlencode($eventId) . $returnQuery;

        $hasStudentRequirements = (bool) ($options['has_student_requirements'] ?? false);
        $isEventCreator = (bool) ($options['is_event_creator'] ?? false);
        $isPaidEvent = (bool) ($options['is_paid_event'] ?? false);
        $canManageExtras = $role === 'admin' || $isEventCreator;

        if ($role === 'student') {
            return;
        }

        echo '<div class="border-b border-zinc-200 mb-6 pt-2">';
        echo '<nav class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Tabs">';

        echo event_tab_link_html($detailsHref, 'Event Details', $currentTab === 'details');
        echo event_tab_link_html($participantsHref, 'Participants', $currentTab === 'participants');

        if ($canManageExtras && $isPaidEvent && !$isFinished) {
            echo event_tab_link_html($paymentsHref, 'Payments', $currentTab === 'payments');
        }

        if ($canManageExtras && $hasStudentRequirements) {
            echo event_tab_link_html(
                $documentReviewHref,
                'Document Review',
                $currentTab === 'document_review'
            );
        }

        if ($role === 'admin' && $isFinished) {
            echo event_tab_link_html($absenceHref, 'Absence Reasons', $currentTab === 'absence_reasons');
        }

        if ($isFinished && ($role === 'admin' || $role === 'teacher')) {
            echo event_tab_link_html($feedbackHref, 'Event Feedback', $currentTab === 'feedback');
        }

        if (!$isFinished && $role === 'admin') {
            echo event_tab_link_html($questionsHref, 'Evaluation Questions', $currentTab === 'questions');
            echo event_tab_link_html($qrHref, 'QR Scanner Access', $currentTab === 'qr');
        }

        echo '</nav>';
        echo '</div>';
    }
}
