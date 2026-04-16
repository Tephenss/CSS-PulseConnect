# CCS PulseConnect Event Lifecycle Checklist

This file is the working source of truth for the agreed event flow from event creation up to certificate delivery.

Use this checklist before changing logic in the web admin, teacher app, or student app.

## Core Rule

The legacy simple-event flow must remain working.

- `simple` events keep the original one-event attendance/evaluation/certificate behavior.
- seminar-based events add session-aware logic on top of the existing flow.
- new seminar logic must not break old simple-event behavior.

## Event Structures

### 1. Simple Event

- One event only
- One attendance record scope
- One event-level evaluation scope
- One certificate scope

### 2. One Seminar

- Parent event remains the container
- One seminar/session is attached to the event
- Attendance can still be treated as seminar-aware internally
- Certificate may be event-level or session-scoped depending on implementation

### 3. Two Seminars

- One parent event
- Two child seminar/session records
- Each seminar has:
  - own title
  - own schedule
  - own attendance window
  - own evaluation block
  - own certificate eligibility

## Lifecycle Flow

### A. Create Event

Expected:

- Admin/teacher can create:
  - simple event
  - one-seminar event
  - two-seminar event
- Seminar-based events must store child session records.
- Parent event start/end should remain valid for event listing, but session schedule is the source of truth for seminar attendance/evaluation/certificate rules.

Key files:

- `api/events_create.php`
- `api/events_update.php` if present
- app teacher create/manage screens

### B. Publish Event

Expected:

- Only published events are visible in active student/teacher event views.
- Registration only applies while event is still open/published.
- Finished events should not disappear into archive immediately if they are still needed for:
  - evaluation
  - certificate sending
  - monitoring

Recommended status behavior:

- `published`: visible as active/open
- `finished`: no longer active, but still available for evaluation/certificates/monitoring
- `archived`: historical only

Key files:

- `api/events_approve.php`
- `events.php`
- `event_view.php`
- app event listing services/screens

### C. Registration / Ticket

Expected:

- Student can register only while registration is open.
- Ticket is tied to the parent event.
- Seminar-based attendance still uses the event ticket, but records attendance per session where needed.

Key files:

- ticket registration APIs
- `my_tickets.php`
- app tickets/event detail screens

### D. QR Attendance

Expected:

- No checkout
- No late logic
- Valid scan result should be `present`
- Scan opens exactly at seminar/event start
- Scan closes after allowed window, usually 30 minutes

Simple event:

- one attendance scope only

Seminar-based event:

- attendance is recorded per seminar/session
- student can be:
  - present in seminar 1
  - absent in seminar 2
- those records must remain independent

Key files:

- `api/scan_context.php`
- `api/scan_ticket.php`
- teacher scanner in app
- participants web/admin views

### E. Finished Event

Expected:

- Once event is finished:
  - it should leave active student/teacher event tabs
  - it should remain available in finished monitoring views
  - evaluation should open automatically if student is eligible
  - admin monitoring pages remain accessible

For seminar-based events:

- session end times must drive actual completion logic where applicable
- effective event end should consider the last seminar/session

### F. Evaluation

Expected:

- Student sees evaluation only after event is finished.
- Student must not see evaluation for events/sessions they are not eligible for.

Simple event:

- event-level evaluation only

Seminar-based event:

- event-level evaluation applies to the whole event
- session-level evaluation only appears for sessions where the student was marked present
- if student attended only seminar 1:
  - they can answer event-level questions
  - they can answer seminar 1 questions
  - they must not receive seminar 2 questions

Admin side:

- Event Feedback must receive submitted student answers
- Evaluation Questions must separate:
  - event-level questions
  - seminar/session-level questions

Key files:

- `evaluation.php`
- `evaluation_admin.php`
- `api/evaluation_submit.php`
- app student evaluation screens/services

### G. Certificate Templates

Expected:

- Certificates are template-based
- Admin creates templates first
- Sending certificates must use saved templates, not fallback placeholder images

Simple event:

- choose one event template for sending

Seminar-based event:

- admin can choose a different template per seminar/session
- example:
  - Seminar 1 -> Template A
  - Seminar 2 -> Template B

Important:

- Template rendering must replace tokens inside the real saved canvas/layout
- participant name must not appear as a fake floating overlay

### H. Certificate Sending

Expected:

- Sending should not be direct-blind send
- Admin should preview/select template assignment before final send

Eligibility:

Simple event:

- student attended the event
- student completed required event evaluation

Seminar-based event:

- student receives only certificates for sessions where they were present
- student must complete:
  - event-level evaluation
  - matching seminar evaluation
- if present only in seminar 1:
  - gets event-level evaluation
  - gets seminar 1 evaluation
  - eligible only for seminar 1 certificate

Student side:

- certificate appears in app certificate list
- preview must use rendered template data
- tap/download should open usable certificate output

Key files:

- `admin_certificates.php`
- `certificate_admin.php`
- `api/certificates_generate.php`
- app student certificates screen/service

## Notification Matrix

### Push + Bell Notifications That Should Exist

#### 1. Teacher Assignment

- recipient: teacher
- trigger: assigned to event
- push: yes
- bell: yes
- destination: teacher event manage screen

#### 2. Event Approved / Rejected

- recipient: teacher creator
- trigger: admin review
- push: yes
- bell: yes
- destination: teacher event manage / event details

#### 3. Event Published / Registration Open

- recipient: students, assigned teachers where applicable
- trigger: event published
- push: yes
- bell: yes
- destination: event details

#### 4. Registration Closed

- recipient: students
- trigger: admin closes registration
- push: optional
- bell: yes
- destination: event details

#### 5. Starting Soon / Ongoing / Ending Soon

- recipient: relevant users
- trigger: time-based milestones
- push: optional depending on UX
- bell: yes
- destination: event details

#### 6. Evaluation Open

- recipient: eligible students only
- trigger: event finished and evaluation available
- push: yes
- bell: yes
- destination: student evaluation flow

#### 7. Certificate Ready

- recipient: eligible students only
- trigger: successful certificate generation
- push: yes
- bell: yes
- destination: student certificates screen

## Current High-Risk Regression Areas

Check these first whenever logic changes:

1. simple events still register, scan, evaluate, and receive certificates
2. seminar-based sessions still use per-session attendance
3. evaluation only opens after finished status / actual event completion
4. feedback answers appear in admin feedback views
5. sending certificates only targets eligible students
6. certificate notifications go to:
   - push
   - bell
   - correct app screen
7. finished events remain visible where monitoring is still required

## Manual Regression Test

### Simple Event

1. Create simple event
2. Publish event
3. Register one student
4. Scan during valid window
5. Finish event
6. Verify evaluation opens for that student
7. Submit evaluation
8. Send certificate
9. Verify student gets:
   - push notification
   - bell notification
   - visible certificate

### Two-Seminar Event

1. Create event with seminar 1 and seminar 2
2. Publish event
3. Register one student
4. Scan only seminar 1
5. Do not scan seminar 2
6. Finish event
7. Verify student sees:
   - event-level evaluation
   - seminar 1 evaluation
   - no seminar 2 evaluation
8. Submit evaluations
9. Send certificates with per-seminar template selection
10. Verify student receives only seminar 1 certificate

## Files Most Likely To Affect This Flow

Web:

- `api/events_create.php`
- `api/events_approve.php`
- `api/scan_context.php`
- `api/scan_ticket.php`
- `api/evaluation_submit.php`
- `api/certificates_generate.php`
- `event_view.php`
- `participants.php`
- `evaluation.php`
- `evaluation_admin.php`
- `admin_certificates.php`
- `certificate_admin.php`
- `includes/event_tabs.php`

App:

- `lib/services/event_service.dart`
- `lib/services/notification_service.dart`
- `lib/services/push_notification_service.dart`
- `lib/widgets/notifications_modal.dart`
- `lib/screens/student/student_events.dart`
- `lib/screens/student/student_event_details.dart`
- `lib/screens/student/student_event_evaluation.dart`
- `lib/screens/student/student_certificates.dart`
- `lib/screens/teacher/teacher_scan.dart`
- `lib/screens/teacher/teacher_event_manage.dart`

## Notes

- If a future change conflicts with this checklist, update both the implementation and this file together.
- If a feature is intentionally changed, document the new rule here first before patching related modules.
