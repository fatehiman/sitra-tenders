{{--
    The body of the "تغییر رمز عبور" page (App\Filament\Pages\ChangePassword).

    Everything here is a Filament component rather than hand-written markup.
    That is not a style preference: the panel's compiled stylesheet contains
    only Filament's own fi-* classes, so Tailwind utility classes written by
    hand render completely unstyled inside the panel. See ARCHITECTURE.md,
    "Panel CSS has no Tailwind utilities".

    wire:submit="save" calls the save() method on the page class. There is no
    action URL and no CSRF token to add — Livewire handles both.

    {{ $this->form }} prints the fields defined in that class's form() method.
--}}
<x-filament-panels::page>
    <form wire:submit="save" class="max-w-lg space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            ذخیره تغییرات
        </x-filament::button>
    </form>
</x-filament-panels::page>
