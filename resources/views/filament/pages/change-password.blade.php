<x-filament-panels::page>
    <form wire:submit="save" class="max-w-lg space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            ذخیره تغییرات
        </x-filament::button>
    </form>
</x-filament-panels::page>
