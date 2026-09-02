<x-lms-layout title="Dashboard" wide>
    {{-- student-portal mounts first, matching parent/dashboard.blade.php's
         own ordering — StudentPortal has no tenant-mismatch check today
         (a separate, already-documented gap, HANDOVER.md's "Avoid" list),
         but this keeps both portals consistent if that ever changes. --}}
    <livewire:student-portal />

    {{-- S17 B6 — semantic search across ingested lesson transcripts,
         wired into the existing student dashboard rather than a new page. --}}
    <livewire:ai.semantic-search />

    {{-- S17 B11 — photo-doubt is course-optional (standalone if asked
         outside a course), so the tenant-wide dashboard is the natural
         embed point, same reasoning as semantic search above. --}}
    <livewire:ai.photo-doubt-upload />

    {{-- S24 B0 — student-side code generation; the phone gets verified
         once the student texts this code to the platform's WhatsApp
         number (B1's inbound webhook). --}}
    <livewire:ai.whatsapp-link-code />

    <livewire:comms.notice-board />
    <livewire:notification-centre />
    <livewire:user-profile />
</x-lms-layout>
