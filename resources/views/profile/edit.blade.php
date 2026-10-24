<x-app-layout :title="__('Profile')">
    <x-slot name="header">
        <h1 class="page-title">
            {{ __('Profile') }}
        </h1>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="card p-5 sm:p-8">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="card p-5 sm:p-8">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="card p-5 sm:p-8">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
