<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Profile Information Form --}}
        <x-filament::section>
            <x-slot name="heading">
                Profile Information
            </x-slot>

            <x-slot name="description">
                Update your account's profile information and email address.
            </x-slot>

            <form wire:submit="updateProfile">
                {{ $this->profileForm }}

                <div class="mt-6">
                    <x-filament::button type="submit">
                        Save Profile
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{-- Update Password Form --}}
        <x-filament::section>
            <x-slot name="heading">
                Update Password
            </x-slot>

            <x-slot name="description">
                Ensure your account is using a long, random password to stay secure.
            </x-slot>

            <form wire:submit="updatePassword">
                {{ $this->passwordForm }}

                <div class="mt-6">
                    <x-filament::button type="submit" color="warning">
                        Update Password
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
