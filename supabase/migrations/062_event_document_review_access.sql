-- Grant selected teachers Document Review access without making them the event creator.
-- First-write-wins stays in PHP (PATCH only while status = pending_review).

alter table public.event_teacher_assignments
  add column if not exists can_review_documents boolean not null default false;

create index if not exists event_teacher_assignments_review_idx
  on public.event_teacher_assignments(event_id)
  where can_review_documents = true;
