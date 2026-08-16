{{--
    The body of the «ارسال پیشنهاد» wizard
    (App\Filament\Resources\Bids\Pages\SubmitSuggestion).

    Almost nothing lives here on purpose. The panel's compiled stylesheet
    contains only Filament's own fi-* classes, so hand-written Tailwind
    utilities render completely unstyled inside the panel — see
    ARCHITECTURE.md, "Panel CSS has no Tailwind utilities". Everything the
    user sees is therefore built from schema components in the page class.

    wire:submit="finalize" is what the wizard's LAST-step submit button
    triggers; the earlier steps' «بعدی» buttons are the Wizard component's
    own and never reach this handler. Livewire supplies the CSRF token and
    the action URL, so there is nothing to add.

    {{ $this->form }} prints the wizard defined in that class's form().
--}}
<x-filament-panels::page>
    <form wire:submit="finalize">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
