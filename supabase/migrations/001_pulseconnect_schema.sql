                                                              -- CCS PulseConnect - Supabase schema
                                                              -- Run this in Supabase SQL Editor.

                                                              create extension if not exists pgcrypto;

-- Permissions for Supabase PostgREST roles.
-- Needed to avoid: "permission denied for schema public"
grant usage on schema public to anon, authenticated, service_role;
grant all privileges on all tables in schema public to anon, authenticated, service_role;
grant all privileges on all sequences in schema public to anon, authenticated, service_role;

alter default privileges in schema public
  grant all on tables to anon, authenticated, service_role;

alter default privileges in schema public
  grant all on sequences to anon, authenticated, service_role;

                                                              -- USERS (app users; NOT auth.users)
                                                              create table if not exists public.users (
                                                                id uuid primary key default gen_random_uuid(),
                                                                first_name text not null,
                                                                middle_name text,
                                                                last_name text not null,
                                                                suffix text,
                                                                email text not null unique,
                                                                password text not null,
                                                                role text not null default 'student',
                                                                created_at timestamptz not null default now(),
                                                                updated_at timestamptz not null default now()
                                                              );

                                                              -- Ensure role exists (for older installs)
                                                              alter table public.users
                                                                add column if not exists role text not null default 'student';

                                                              do $$
                                                              begin
                                                                if not exists (
                                                                  select 1
                                                                  from pg_constraint
                                                                  where conname = 'users_role_check'
                                                                ) then
                                                                  alter table public.users
                                                                    add constraint users_role_check
                                                                    check (role in ('admin','teacher','student'));
                                                                end if;
                                                              end $$;

-- Re-apply grants after table creation (idempotent, but helps on some projects).
grant usage on schema public to anon, authenticated, service_role;
grant all privileges on all tables in schema public to anon, authenticated, service_role;
grant all privileges on all sequences in schema public to anon, authenticated, service_role;

                                                              -- EVENTS
                                                              create table if not exists public.events (
                                                                id uuid primary key default gen_random_uuid(),
                                                                title text not null,
                                                                description text,
                                                                location text,
                                                                start_at timestamptz not null,
                                                                end_at timestamptz not null,
                                                                status text not null default 'pending', -- draft/pending/approved/published/archived
                                                                cover_image_url text,
                                                                created_by uuid not null references public.users(id) on delete restrict,
                                                                approved_by uuid references public.users(id) on delete set null,
                                                                created_at timestamptz not null default now(),
                                                                updated_at timestamptz not null default now()
                                                              );

                                                              do $$
                                                              begin
                                                                if not exists (select 1 from pg_constraint where conname = 'events_status_check') then
                                                                  alter table public.events
                                                                    add constraint events_status_check
                                                                    check (status in ('draft','pending','approved','published','archived'));
                                                                end if;
                                                              end $$;

                                                              create index if not exists events_status_idx on public.events(status);
                                                              create index if not exists events_start_at_idx on public.events(start_at);

                                                              -- REGISTRATIONS
                                                              create table if not exists public.event_registrations (
                                                                id uuid primary key default gen_random_uuid(),
                                                                event_id uuid not null references public.events(id) on delete cascade,
                                                                student_id uuid not null references public.users(id) on delete cascade,
                                                                registered_at timestamptz not null default now(),
                                                                unique (event_id, student_id)
                                                              );

                                                              create index if not exists event_registrations_event_idx on public.event_registrations(event_id);
                                                              create index if not exists event_registrations_student_idx on public.event_registrations(student_id);

                                                              -- TICKETS (QR tokens)
                                                              create table if not exists public.tickets (
                                                                id uuid primary key default gen_random_uuid(),
                                                                registration_id uuid not null references public.event_registrations(id) on delete cascade,
                                                                token text not null unique,
                                                                issued_at timestamptz not null default now()
                                                              );

                                                              create index if not exists tickets_registration_idx on public.tickets(registration_id);

                                                              -- ATTENDANCE
                                                              create table if not exists public.attendance (
                                                                id uuid primary key default gen_random_uuid(),
                                                                ticket_id uuid not null unique references public.tickets(id) on delete cascade,
                                                                check_in_at timestamptz,
                                                                check_out_at timestamptz,
                                                                status text not null default 'unscanned', -- unscanned/present/late/early/invalid
                                                                last_scanned_by uuid references public.users(id) on delete set null,
                                                                last_scanned_at timestamptz,
                                                                updated_at timestamptz not null default now()
                                                              );

                                                              do $$
                                                              begin
                                                                if not exists (select 1 from pg_constraint where conname = 'attendance_status_check') then
                                                                  alter table public.attendance
                                                                    add constraint attendance_status_check
                                                                    check (status in ('unscanned','present','late','early','invalid'));
                                                                end if;
                                                              end $$;

                                                              -- EVALUATION QUESTIONS
                                                              create table if not exists public.evaluation_questions (
                                                                id uuid primary key default gen_random_uuid(),
                                                                event_id uuid not null references public.events(id) on delete cascade,
                                                                question_text text not null,
                                                                field_type text not null default 'text', -- text|rating
                                                                required boolean not null default false,
                                                                sort_order int not null default 0,
                                                                created_at timestamptz not null default now(),
                                                                updated_at timestamptz not null default now()
                                                              );

                                                              do $$
                                                              begin
                                                                if not exists (select 1 from pg_constraint where conname = 'evaluation_field_type_check') then
                                                                  alter table public.evaluation_questions
                                                                    add constraint evaluation_field_type_check
                                                                    check (field_type in ('text','rating'));
                                                                end if;
                                                              end $$;

                                                              create index if not exists evaluation_questions_event_idx on public.evaluation_questions(event_id);

                                                              -- EVALUATION ANSWERS
                                                              create table if not exists public.evaluation_answers (
                                                                id uuid primary key default gen_random_uuid(),
                                                                event_id uuid not null references public.events(id) on delete cascade,
                                                                question_id uuid not null references public.evaluation_questions(id) on delete cascade,
                                                                student_id uuid not null references public.users(id) on delete cascade,
                                                                answer_text text,
                                                                submitted_at timestamptz not null default now(),
                                                                unique (question_id, student_id)
                                                              );

                                                              create index if not exists evaluation_answers_event_idx on public.evaluation_answers(event_id);
                                                              create index if not exists evaluation_answers_student_idx on public.evaluation_answers(student_id);

                                                              -- CERTIFICATE TEMPLATES
                                                              create table if not exists public.certificate_templates (
                                                                id uuid primary key default gen_random_uuid(),
                                                                event_id uuid not null unique references public.events(id) on delete cascade,
                                                                title text not null default 'Certificate of Participation',
                                                                body_text text not null default 'This certifies that {{name}} participated in {{event}}.',
                                                                footer_text text,
                                                                created_by uuid references public.users(id) on delete set null,
                                                                created_at timestamptz not null default now(),
                                                                updated_at timestamptz not null default now()
                                                              );

                                                              -- CERTIFICATES
                                                              create table if not exists public.certificates (
                                                                id uuid primary key default gen_random_uuid(),
                                                                event_id uuid not null references public.events(id) on delete cascade,
                                                                student_id uuid not null references public.users(id) on delete cascade,
                                                                certificate_code text not null unique,
                                                                issued_at timestamptz not null default now(),
                                                                issued_by uuid references public.users(id) on delete set null,
                                                                unique (event_id, student_id)
                                                              );

                                                              create index if not exists certificates_event_idx on public.certificates(event_id);
                                                              create index if not exists certificates_student_idx on public.certificates(student_id);

-- CCS PulseConnect - Supabase schema migration 002
-- Add sections table and link to users

create table if not exists public.sections (
    id uuid primary key default gen_random_uuid(),
    name text not null unique,
    created_at timestamptz not null default now()
);

-- Give permissions
grant all privileges on table public.sections to anon, authenticated, service_role;

-- Alter users table to include section_id
alter table public.users
add column if not exists section_id uuid references public.sections(id) on delete set null;

-- Add contact number to users
alter table public.users
add column if not exists contact_number text;

-- NOTIFICATION SYNC TRACKING
-- Tracks individual notifications clicked by the user
create table if not exists public.user_notification_reads (
    user_id uuid not null references public.users(id) on delete cascade,
    notification_id text not null,
    read_at timestamptz not null default now(),
    primary key (user_id, notification_id)
);

-- Tracks the "Mark All As Read" horizon timestamp
create table if not exists public.user_notification_watermarks (
    user_id uuid primary key references public.users(id) on delete cascade,
    last_read_at timestamptz not null default now()
);

-- Ensure anon access (since app uses broad grants temporarily)
grant all privileges on table public.user_notification_reads to anon, authenticated;
grant all privileges on table public.user_notification_watermarks to anon, authenticated;
