<x-admin-layout>
    <x-slot name="header">New admin SMTP</x-slot>

    <x-page-header title="New platform SMTP account"
                   subtitle="A platform account is owned by you, not by a customer. It reaches nobody until you assign it on the next screen."
                   :back="route('admin.smtp.index')" />

    @include('admin.smtp.partials.form')
</x-admin-layout>
