<x-lms-layout title="{{ $session->title ?? 'Session' }} — Check In">
    <livewire:geofence-check-in :session="$session" />
</x-lms-layout>
