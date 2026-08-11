<div class="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-4 py-10">
    <h1 class="mb-1 text-center text-xl font-bold">سامانه مناقصات سیترا</h1>
    <p class="mb-6 text-center text-sm text-gray-500">ثبت‌نام کاربر جدید</p>

    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        @if ($formError)
            <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $formError }}</div>
        @endif

        <form wire:submit.prevent="sendOtp" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">نام</label>
                    <input type="text" wire:model="first_name"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-primary-500 focus:outline-none">
                    @error('first_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">نام خانوادگی</label>
                    <input type="text" wire:model="last_name"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-primary-500 focus:outline-none">
                    @error('last_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">شماره موبایل</label>
                    <input type="tel" wire:model="mobile" placeholder="09XXXXXXXXX"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-primary-500 focus:outline-none">
                    @error('mobile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">کد ملی</label>
                    <input type="text" wire:model="national_id" maxlength="10"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-primary-500 focus:outline-none">
                    @error('national_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">نوع شخص</label>
                <div class="flex gap-6">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="person_type" value="individual">
                        حقیقی
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="person_type" value="company">
                        حقوقی
                    </label>
                </div>
                @error('person_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if ($person_type === 'company')
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 rounded-lg bg-gray-50 p-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">نام شرکت</label>
                        <input type="text" wire:model="company_name"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-primary-500 focus:outline-none">
                        @error('company_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">شناسه ملی</label>
                        <input type="text" wire:model="company_national_id" maxlength="11"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-primary-500 focus:outline-none">
                        @error('company_national_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">رمز عبور</label>
                    <input type="password" wire:model="password"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-primary-500 focus:outline-none">
                    @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">تکرار رمز عبور</label>
                    <input type="password" wire:model="password_confirmation"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-primary-500 focus:outline-none">
                </div>
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-amber-500 px-4 py-2.5 font-medium text-white hover:bg-amber-600"
                    wire:loading.attr="disabled" wire:target="sendOtp">
                <span wire:loading.remove wire:target="sendOtp">ارسال کد تایید</span>
                <span wire:loading wire:target="sendOtp">در حال ارسال...</span>
            </button>
        </form>

        <p class="mt-4 text-center text-sm text-gray-500">
            قبلاً ثبت‌نام کرده‌اید؟ <a href="/login" class="text-amber-600 hover:underline">ورود</a>
        </p>
    </div>
</div>

@if ($showOtpModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-lg">
            <h2 class="mb-2 text-lg font-bold">تایید شماره موبایل</h2>
            <p class="mb-4 text-sm text-gray-500">کد ۶ رقمی ارسال شده به {{ $mobile }} را وارد کنید.</p>

            <input type="text" wire:model="otp_code" maxlength="6" inputmode="numeric"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-center text-lg tracking-widest focus:border-primary-500 focus:outline-none"
                   placeholder="______" autofocus>
            @if ($otpError)
                <p class="mt-2 text-xs text-red-600">{{ $otpError }}</p>
            @endif

            <div class="mt-4 flex gap-2">
                <button wire:click="confirmOtp"
                        class="flex-1 rounded-lg bg-amber-500 px-4 py-2.5 font-medium text-white hover:bg-amber-600"
                        wire:loading.attr="disabled" wire:target="confirmOtp">
                    تایید و ثبت‌نام
                </button>
                <button wire:click="closeOtpModal" type="button"
                        class="rounded-lg border border-gray-300 px-4 py-2.5 text-gray-700 hover:bg-gray-50">
                    بستن
                </button>
            </div>

            <button wire:click="resendOtp" type="button"
                    class="mt-3 w-full text-center text-sm text-amber-600 hover:underline">
                ارسال مجدد کد
            </button>
        </div>
    </div>
@endif
