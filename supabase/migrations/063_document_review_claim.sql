-- Soft lock while a teacher has a pending submission open (before approve/decline).
-- Stale claims (older than app TTL) can be taken over by another reviewer.

alter table public.event_student_submissions
    add column if not exists review_claimed_by uuid references public.users(id) on delete set null,
    add column if not exists review_claimed_at timestamptz;

create index if not exists event_student_submissions_claim_idx
    on public.event_student_submissions(event_id, review_claimed_by)
    where review_claimed_by is not null;
